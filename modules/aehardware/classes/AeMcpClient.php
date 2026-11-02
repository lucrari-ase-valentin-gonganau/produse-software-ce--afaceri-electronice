<?php
/**
 * Client MCP minimal (transport Streamable HTTP): initialize, tools/list, tools/call.
 * Chatbotul afla ce produse exista doar prin acest client, de la serverul MCP al magazinului.
 */
class AeMcpClient
{
    const PROTOCOL_VERSION = '2025-06-18';

    /** @var string */
    private $url;

    /** @var string[] antete HTTP suplimentare */
    private $headers;

    /** @var string|null versiunea negociata la initialize */
    private $protocolVersion;

    /** @var string|null */
    private $sessionId;

    private $nextId = 1;

    public function __construct($url, array $headers = [])
    {
        $this->url = $url;
        $this->headers = $headers;
    }

    /**
     * Clientul pentru serverul MCP al acestui magazin, apelat direct in container (127.0.0.1), fara sa iasa
     * pe internet. Host-ul trebuie sa fie domeniul magazinului, altfel PrestaShop redirectioneaza;
     * X-Forwarded-Proto evita redirectionarea catre HTTPS cand SSL e activ (ca in spatele lui Caddy).
     */
    public static function forShop()
    {
        $headers = ['Host: ' . Tools::getShopDomain()];
        if (Configuration::get('PS_SSL_ENABLED')) {
            $headers[] = 'X-Forwarded-Proto: https';
        }

        return new self('http://127.0.0.1' . __PS_BASE_URI__ . 'index.php?fc=module&module=aehardware&controller=mcp', $headers);
    }

    /** uneltele serverului: [[name, description, inputSchema], ...] */
    public function listTools()
    {
        $this->initialize();

        return $this->request('tools/list')['tools'] ?? [];
    }

    /** apeleaza o unealta si intoarce textul rezultatului (erorile uneltei incep cu "Eroare:") */
    public function callTool($name, array $arguments)
    {
        $this->initialize();
        $result = $this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        $text = implode("\n", array_column(array_filter($result['content'] ?? [], function ($item) {
            return ($item['type'] ?? '') === 'text';
        }), 'text'));

        return empty($result['isError']) ? $text : 'Eroare: ' . $text;
    }

    private function initialize()
    {
        if ($this->protocolVersion) {
            return;
        }
        $result = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'aehardware-chatbot', 'version' => '1.0.0'],
        ]);
        $this->protocolVersion = $result['protocolVersion'] ?? self::PROTOCOL_VERSION;
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    private function request($method, array $params = [])
    {
        $id = $this->nextId++;
        $response = $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
        if (isset($response['error'])) {
            throw new RuntimeException("MCP $method: " . ($response['error']['message'] ?? 'eroare'));
        }
        if (!isset($response['result'])) {
            throw new RuntimeException("MCP $method: raspuns fara rezultat");
        }

        return $response['result'];
    }

    /** trimite un mesaj; intoarce raspunsul JSON-RPC (sau null pentru notificari, HTTP 202) */
    private function send(array $message)
    {
        $headers = array_merge($this->headers, [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ]);
        if ($this->protocolVersion) {
            $headers[] = 'MCP-Protocol-Version: ' . $this->protocolVersion;
        }
        if ($this->sessionId) {
            $headers[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }

        $responseHeaders = [];
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Serverul MCP nu raspunde: ' . $error);
        }
        if (isset($responseHeaders['mcp-session-id'])) {
            $this->sessionId = $responseHeaders['mcp-session-id'];
        }
        if ($status === 202 && !isset($message['id'])) {
            return null;
        }
        if ($status !== 200) {
            throw new RuntimeException("Serverul MCP a raspuns cu HTTP $status");
        }

        // raspunsul poate fi JSON simplu sau un flux SSE ("data: {...}")
        if (strpos($responseHeaders['content-type'] ?? '', 'text/event-stream') === 0) {
            foreach (preg_split('/\r?\n/', $body) as $line) {
                if (strpos($line, 'data:') === 0) {
                    $data = json_decode(trim(substr($line, 5)), true);
                    if (isset($data['id']) && $data['id'] === ($message['id'] ?? null)) {
                        return $data;
                    }
                }
            }
            throw new RuntimeException('Serverul MCP nu a trimis raspunsul in fluxul SSE');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Serverul MCP a trimis un raspuns invalid');
        }

        return $data;
    }
}
