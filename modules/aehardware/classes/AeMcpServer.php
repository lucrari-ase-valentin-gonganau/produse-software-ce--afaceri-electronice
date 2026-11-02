<?php
/**
 * Server MCP (Model Context Protocol) pentru catalogul magazinului: doar citire, doar produse si categorii.
 *
 * Primeste mesaje JSON-RPC 2.0 (transport "Streamable HTTP", fara sesiuni, raspuns JSON) prin
 * controllers/front/mcp.php: /module/aehardware/mcp. Il foloseste chatbotul (AeMcpClient), dar se poate
 * conecta orice client MCP (MCP Inspector, Claude Desktop etc.).
 */
class AeMcpServer
{
    /** versiunile protocolului MCP acceptate, cea mai noua prima */
    const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    const MAX_RESULTS = 30;

    /** @var AeCatalog */
    private $catalog;

    /** @var AeCompatibility */
    private $compatibility;

    /** @var string */
    private $builderUrl;

    public function __construct(AeCatalog $catalog, $builderUrl)
    {
        $this->catalog = $catalog;
        $this->compatibility = new AeCompatibility($catalog);
        $this->builderUrl = $builderUrl;
    }

    /**
     * Un mesaj JSON-RPC => raspunsul (array) sau null pentru notificari.
     */
    public function handle($message)
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error($message['id'] ?? null, -32600, 'Cerere JSON-RPC invalida');
        }
        $isNotification = !array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'initialize':
                $result = $this->initialize($params);
                break;
            case 'ping':
                $result = new stdClass();
                break;
            case 'tools/list':
                $result = ['tools' => $this->tools()];
                break;
            case 'tools/call':
                $name = $params['name'] ?? '';
                if (!in_array($name, array_column($this->tools(), 'name'), true)) {
                    return self::error($message['id'] ?? null, -32602, "Unealta necunoscuta: $name");
                }
                $result = $this->callTool($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
                break;
            default:
                // notificarile (ex. notifications/initialized) nu primesc raspuns
                return $isNotification ? null : self::error($message['id'], -32601, 'Metoda necunoscuta: ' . $message['method']);
        }

        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result];
    }

    private function initialize(array $params)
    {
        $requested = $params['protocolVersion'] ?? '';

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'aehardware-catalog', 'title' => 'Catalog ' . Configuration::get('PS_SHOP_NAME'), 'version' => '1.0.0'],
            'instructions' => 'Catalogul magazinului online ' . Configuration::get('PS_SHOP_NAME') . ' (componente hardware). '
                . 'Preturile sunt in lei, cu TVA. Pentru compatibilitatea componentelor unui PC foloseste '
                . 'produse_compatibile si verifica_configuratie. Configuratorul online: ' . $this->builderUrl,
        ];
    }

    /** definitiile uneltelor (nume, descriere, schema argumentelor) */
    public function tools()
    {
        $slots = array_keys(AeCompatibility::SLOTS);

        return [
            [
                'name' => 'lista_categorii',
                'title' => 'Categoriile magazinului',
                'description' => 'Arborele de categorii al magazinului, cu numarul de produse si linkul fiecarei categorii.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'cauta_produse',
                'title' => 'Cauta produse',
                'description' => 'Cauta produse active dupa text (nume, marca, referinta sau caracteristici, ex. "AM5", "DDR5", "RTX"), '
                    . 'categorie si pret maxim. Intoarce referinta, numele, pretul cu TVA, stocul, linkul si caracteristicile.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Cuvinte cautate; toate trebuie sa apara'],
                        'categorie' => ['type' => 'string', 'description' => 'Numele categoriei, ex. "Placi video" sau "Procesoare AMD" (include subcategoriile)'],
                        'pret_maxim' => ['type' => 'number', 'description' => 'Pretul maxim, in lei cu TVA'],
                        'doar_in_stoc' => ['type' => 'boolean', 'description' => 'Doar produsele disponibile in stoc'],
                        'limita' => ['type' => 'integer', 'description' => 'Numarul maxim de rezultate (implicit 10, maxim ' . self::MAX_RESULTS . ')'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'detalii_produs',
                'title' => 'Detaliile unui produs',
                'description' => 'Toate detaliile unui produs dupa referinta (ex. AE-CPU-001): pret, stoc, caracteristici, descriere, link.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['referinta' => ['type' => 'string', 'description' => 'Referinta produsului, ex. AE-GPU-003']],
                    'required' => ['referinta'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'produse_compatibile',
                'title' => 'Produse compatibile',
                'description' => 'Produsele dintr-o componenta de PC compatibile cu componentele deja alese '
                    . '(socket procesor/placa de baza, tip memorie DDR4/DDR5, format placa/carcasa, puterea sursei fata de '
                    . 'consumul tuturor componentelor). '
                    . 'Componente: ' . implode(', ', $slots) . '.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'componenta' => ['type' => 'string', 'enum' => $slots, 'description' => 'Componenta cautata'],
                        'selectie' => [
                            'type' => 'object',
                            'description' => 'Componentele deja alese: componenta => referinta produsului, ex. {"procesor": "AE-CPU-001"}',
                            'properties' => array_fill_keys($slots, ['type' => 'string']),
                        ],
                    ],
                    'required' => ['componenta'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'verifica_configuratie',
                'title' => 'Verifica o configuratie de PC',
                'description' => 'Verifica daca mai multe componente (referinte) sunt compatibile intre ele si calculeaza pretul total, '
                    . 'consumul fiecarei componente, consumul total si puterea minima a sursei (consum + 30% rezerva).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'referinte' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Referintele produselor, ex. ["AE-CPU-001", "AE-MB-001"]'],
                    ],
                    'required' => ['referinte'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /** rezultatul unei unelte, ca text JSON; erorile de argumente se intorc cu isError (le vede modelul) */
    private function callTool($name, array $args)
    {
        try {
            switch ($name) {
                case 'lista_categorii':
                    $data = $this->catalog->categories();
                    break;
                case 'cauta_produse':
                    $data = $this->search($args);
                    break;
                case 'detalii_produs':
                    $data = $this->details($args);
                    break;
                case 'produse_compatibile':
                    $data = $this->compatible($args);
                    break;
                default:
                    $data = $this->checkBuild($args);
            }
        } catch (InvalidArgumentException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];
    }

    private function search(array $args)
    {
        $limit = max(1, min(self::MAX_RESULTS, (int) ($args['limita'] ?? 10)));
        $filters = array_intersect_key($args, array_flip(['text', 'categorie', 'pret_maxim', 'doar_in_stoc']));
        if (!empty($filters['categorie']) && !$this->catalog->categoryId($filters['categorie'])) {
            throw new InvalidArgumentException('Categoria "' . $filters['categorie'] . '" nu exista. Foloseste lista_categorii.');
        }
        $products = $this->catalog->products($filters);

        return [
            'gasite' => count($products),
            'afisate' => min($limit, count($products)),
            'produse' => array_map([$this, 'compact'], array_slice($products, 0, $limit)),
        ];
    }

    private function details(array $args)
    {
        $product = $this->catalog->product(trim((string) ($args['referinta'] ?? '')));
        if (!$product) {
            throw new InvalidArgumentException('Nu exista un produs activ cu referinta "' . ($args['referinta'] ?? '') . '".');
        }
        unset($product['id'], $product['pret'], $product['imagine']);

        return $product;
    }

    private function compatible(array $args)
    {
        $slot = $args['componenta'] ?? '';
        if (!isset(AeCompatibility::SLOTS[$slot])) {
            throw new InvalidArgumentException('Componenta necunoscuta. Valori posibile: ' . implode(', ', array_keys(AeCompatibility::SLOTS)));
        }
        $selection = $this->selectionFromReferences(array_values(is_array($args['selectie'] ?? null) ? $args['selectie'] : []));
        $result = $this->compatibility->candidates($slot, $selection);

        return [
            'componenta' => $slot,
            'componente_alese' => array_map(function ($product) {
                return $product['referinta'] . ' ' . $product['nume'];
            }, $selection),
            'conditii' => $result['conditii'],
            'compatibile' => array_map([$this, 'compact'], $result['compatibile']),
            'incompatibile_ascunse' => $result['incompatibile'],
        ];
    }

    private function checkBuild(array $args)
    {
        $references = is_array($args['referinte'] ?? null) ? $args['referinte'] : [];
        if (!$references) {
            throw new InvalidArgumentException('Da cel putin o referinta de produs.');
        }
        $summary = $this->compatibility->summary($this->selectionFromReferences($references));
        foreach ($summary['componente'] as &$item) {
            $item['produs'] = $this->compact($item['produs']);
        }

        return [
            'compatibila' => !$summary['probleme'],
            'probleme' => $summary['probleme'],
            'componente' => $summary['componente'],
            'componente_lipsa' => $summary['lipsa'],
            'total' => $summary['total_text'],
            'consum_pe_componente' => array_map(function ($row) {
                return ['componenta' => $row['eticheta'], 'produs' => $row['produs'], 'consum_w' => $row['w']];
            }, $summary['consum_componente']),
            'consum_estimat_w' => $summary['consum_estimat'],
            'sursa_recomandata_w' => $summary['sursa_recomandata'],
            'configurator' => $this->builderUrl,
        ];
    }

    /** referinte => [componenta => produs]; refuza produsele care nu sunt componente de PC sau doua din aceeasi componenta */
    private function selectionFromReferences(array $references)
    {
        $selection = [];
        foreach ($references as $reference) {
            $product = $this->catalog->product(trim((string) $reference));
            $slot = $product ? $this->compatibility->slotOf($product['id']) : null;
            if (!$slot) {
                throw new InvalidArgumentException("Referinta \"$reference\" nu este o componenta de PC din catalog.");
            }
            if (isset($selection[$slot])) {
                throw new InvalidArgumentException("Ai dat doua produse pentru componenta $slot.");
            }
            $selection[$slot] = $this->compatibility->resolve([$slot => $product['id']])[$slot];
        }

        return $selection;
    }

    /** forma scurta a unui produs, pentru listele din raspunsuri */
    private function compact(array $product)
    {
        return [
            'referinta' => $product['referinta'],
            'nume' => $product['nume'],
            'categorie' => $product['categorie'],
            'pret' => $product['pret_text'],
            'stoc' => $product['stoc'] > 0 ? $product['stoc'] . ' buc.' : 'stoc epuizat',
            'url' => $product['url'],
            'caracteristici' => $product['caracteristici'],
        ];
    }

    private static function error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
