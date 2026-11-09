<?php
/**
 * Chatbotul magazinului: OpenAI Chat Completions + uneltele serverului MCP al catalogului.
 *
 * La fiecare mesaj: ia lista de unelte de la serverul MCP, o trimite modelului ca "functions",
 * iar cand modelul cere o unealta o apeleaza prin MCP si ii da rezultatul, pana raspunde in text.
 *
 * In plus are o unealta proprie, cerinte_joc, care cauta pe internet cerintele de sistem ale unui joc
 * (un model OpenAI cu web search). Nu e in serverul MCP: acela e public si ar consuma cheia OpenAI.
 */
class AeChatbot
{
    /** cate runde de apeluri de unelte sunt permise pentru un mesaj (o configuratie completa are nevoie de mai multe) */
    const MAX_TOOL_ROUNDS = 12;

    const GAME_TOOL = 'cerinte_joc';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var string ex. https://api.openai.com/v1 (sau alt API compatibil OpenAI) */
    private $apiUrl;

    /** @var AeMcpClient */
    private $mcp;

    /** @var string modelul cu cautare pe internet pentru cerinte_joc ('' = unealta dezactivata) */
    private $searchModel;

    /** @var array unelte proprii adaugate cu addTool: nume => [definitia pentru model, functia] */
    private $localTools = [];

    /** @var array uneltele apelate la ultimul mesaj, in ordine: [[nume, rezultat text, argumente], ...] */
    private $toolLog = [];

    /** uneltele apelate la ultimul mesaj (pentru depanare): [[nume, rezultat text, argumente], ...] */
    public function toolLog()
    {
        return $this->toolLog;
    }

    public function __construct($apiKey, $model, $apiUrl, AeMcpClient $mcp, $searchModel = '')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->mcp = $mcp;
        $this->searchModel = (string) $searchModel;
    }

    /**
     * Ultimul rezultat al uneia dintre unelte, decodat din JSON (ex. configuratia verificata, ca pagina s-o
     * afiseze exact, nu cum o rescrie modelul); null daca n-a fost apelata sau a intors o eroare.
     */
    public function lastToolResult(array $names)
    {
        foreach (array_reverse($this->toolLog) as list($name, $result)) {
            if (in_array($name, $names, true)) {
                $data = json_decode($result, true);

                return is_array($data) ? $data : null;
            }
        }

        return null;
    }

    /**
     * O unealta proprie, care nu e in serverul MCP (ex. adauga_in_cos, care lucreaza cu sesiunea vizitatorului).
     *
     * @param callable $handler primeste argumentele (array) si intoarce rezultatul, text sau array (trimis ca JSON)
     */
    public function addTool($name, $description, array $parameters, callable $handler)
    {
        $this->localTools[$name] = [
            ['type' => 'function', 'function' => ['name' => $name, 'description' => $description, 'parameters' => $parameters]],
            $handler,
        ];
    }

    /**
     * @param array $history mesajele conversatiei: [['role' => 'user'|'assistant', 'content' => '...'], ...]
     *
     * @return string raspunsul asistentului (Markdown simplu)
     */
    public function reply(array $history, $systemPrompt)
    {
        $tools = array_map(function ($tool) {
            $schema = $tool['inputSchema'] ?? ['type' => 'object'];
            // json_decode(..., true) transforma "properties": {} in [], pe care OpenAI il respinge
            if (empty($schema['properties'])) {
                $schema['properties'] = new stdClass();
            }

            return ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $schema,
            ]];
        }, $this->mcp->listTools());
        if ($this->searchModel !== '') {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => self::GAME_TOOL,
                'description' => 'Cauta pe internet cerintele de sistem ale unui joc pe PC (minime si recomandate: procesor, '
                    . 'placa video, RAM, spatiu de stocare), cu sursa. Foloseste-o inainte de a recomanda o configuratie pentru un joc.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['joc' => ['type' => 'string', 'description' => 'Numele jocului, ex. "Cyberpunk 2077"']],
                    'required' => ['joc'],
                ],
            ]];
        }
        foreach ($this->localTools as list($definition)) {
            $tools[] = $definition;
        }

        $messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $history);
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; ++$round) {
            // in ultima runda nu mai dam unelte, ca modelul sa raspunda cu ce a aflat
            $message = $this->complete($messages, $round < self::MAX_TOOL_ROUNDS ? $tools : [], null, $this->reasoning());
            if (empty($message['tool_calls'])) {
                return trim((string) ($message['content'] ?? ''));
            }
            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $message['tool_calls']];
            foreach ($message['tool_calls'] as $call) {
                $arguments = json_decode($call['function']['arguments'] ?? '', true);
                $arguments = is_array($arguments) ? $arguments : [];
                $name = $call['function']['name'];
                if ($name === self::GAME_TOOL) {
                    $result = $this->gameRequirements((string) ($arguments['joc'] ?? ''));
                } elseif (isset($this->localTools[$name])) {
                    $result = call_user_func($this->localTools[$name][1], $arguments);
                    $result = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $result = $this->mcp->callTool($name, $arguments);
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $result];
                $this->toolLog[] = [$name, $result, $arguments];
            }
        }

        return '';
    }

    /**
     * Rationament scurt inainte de raspuns: cu el modelul respecta mai bine pasii si regulile (stoc, toate
     * componentele). In /chat/completions, impreuna cu unelte, merge doar la gpt-5, gpt-5-mini, gpt-5-nano;
     * gpt-5.x (5.4, 5.5) il refuza acolo (ar cere /v1/responses), deci la ele nu se trimite.
     */
    private function reasoning()
    {
        return preg_match('/^gpt-5(-mini|-nano)?(-\d{4}-\d{2}-\d{2})?$/', $this->model) ? ['reasoning_effort' => 'low'] : [];
    }

    /**
     * Unealta cerinte_joc: intreaba modelul cu web search. Erorile se intorc ca text, ca asistentul
     * sa poata continua (ex. cu cerintele pe care le stie el, spunand ca nu sunt verificate).
     */
    private function gameRequirements($game)
    {
        $game = trim(mb_substr($game, 0, 100));
        if ($game === '') {
            return 'Eroare: lipseste numele jocului.';
        }
        try {
            $message = $this->complete([[
                'role' => 'user',
                'content' => "Cerintele de sistem oficiale pentru jocul \"$game\" pe PC (Windows): minime si recomandate, "
                    . 'separat pentru procesor, placa video (cu memoria video), RAM si spatiu de stocare (si daca cere SSD). '
                    . 'Daca exista si cerinte pentru rezolutii mai mari (1440p, 4K) sau ray tracing, adauga-le pe scurt. '
                    . 'Raspunde in romana, concis, cu sursa. Daca jocul nu exista sau nu are versiune de PC, spune asta. '
                    . 'Daca textul dintre ghilimele nu este numele unui joc video, nu cauta nimic si raspunde doar: '
                    . 'Nu este un joc video; caut doar cerintele de sistem ale jocurilor.',
            ]], [], $this->searchModel, ['web_search_options' => new stdClass()]);
        } catch (Exception $e) {
            return 'Cautarea pe internet nu a mers: ' . $e->getMessage();
        }

        return trim((string) ($message['content'] ?? '')) ?: 'Nu am gasit cerintele jocului.';
    }

    /** o cerere catre /chat/completions; intoarce mesajul asistentului */
    private function complete(array $messages, array $tools, $model = null, array $extra = [])
    {
        $payload = ['model' => $model ?: $this->model, 'messages' => $messages] + $extra;
        if ($tools) {
            $payload['tools'] = $tools;
        }

        $curl = curl_init($this->apiUrl . '/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('API-ul OpenAI nu raspunde: ' . $error);
        }
        $data = json_decode($body, true);
        if ($status !== 200) {
            throw new RuntimeException("API-ul OpenAI a raspuns cu HTTP $status: " . ($data['error']['message'] ?? substr($body, 0, 300)));
        }
        if (!isset($data['choices'][0]['message'])) {
            throw new RuntimeException('API-ul OpenAI a trimis un raspuns fara mesaj');
        }

        return $data['choices'][0]['message'];
    }

    /** verifica cheia: lista de modele (folosit de butonul "Testeaza" din admin) */
    public function testKey()
    {
        $curl = curl_init($this->apiUrl . '/models');
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = json_decode((string) $body, true);
        if ($status !== 200) {
            throw new RuntimeException("HTTP $status: " . ($data['error']['message'] ?? 'fara raspuns'));
        }

        return in_array($this->model, array_column($data['data'] ?? [], 'id'), true);
    }
}
