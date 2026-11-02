<?php
/**
 * Chatbotul magazinului: OpenAI Chat Completions + uneltele serverului MCP al catalogului.
 *
 * La fiecare mesaj: ia lista de unelte de la serverul MCP, o trimite modelului ca "functions",
 * iar cand modelul cere o unealta o apeleaza prin MCP si ii da rezultatul, pana raspunde in text.
 */
class AeChatbot
{
    /** cate runde de apeluri de unelte sunt permise pentru un mesaj */
    const MAX_TOOL_ROUNDS = 6;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var string ex. https://api.openai.com/v1 (sau alt API compatibil OpenAI) */
    private $apiUrl;

    /** @var AeMcpClient */
    private $mcp;

    public function __construct($apiKey, $model, $apiUrl, AeMcpClient $mcp)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->mcp = $mcp;
    }

    /**
     * @param array $history mesajele conversatiei: [['role' => 'user'|'assistant', 'content' => '...'], ...]
     *
     * @return string raspunsul asistentului (Markdown simplu)
     */
    public function reply(array $history, $systemPrompt)
    {
        $tools = array_map(function ($tool) {
            return ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass()],
            ]];
        }, $this->mcp->listTools());

        $messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $history);
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; ++$round) {
            // in ultima runda nu mai dam unelte, ca modelul sa raspunda cu ce a aflat
            $message = $this->complete($messages, $round < self::MAX_TOOL_ROUNDS ? $tools : []);
            if (empty($message['tool_calls'])) {
                return trim((string) ($message['content'] ?? ''));
            }
            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $message['tool_calls']];
            foreach ($message['tool_calls'] as $call) {
                $arguments = json_decode($call['function']['arguments'] ?? '', true);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => $this->mcp->callTool($call['function']['name'], is_array($arguments) ? $arguments : []),
                ];
            }
        }

        return '';
    }

    /** o cerere catre /chat/completions; intoarce mesajul asistentului */
    private function complete(array $messages, array $tools)
    {
        $payload = ['model' => $this->model, 'messages' => $messages];
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
