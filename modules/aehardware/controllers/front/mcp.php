<?php
/**
 * Serverul MCP al catalogului: POST /module/aehardware/mcp
 * Transport "Streamable HTTP" fara sesiuni: fiecare POST contine un mesaj JSON-RPC (sau o lista),
 * raspunsul vine ca JSON; notificarile primesc HTTP 202. Nu exista flux SSE (GET => 405).
 */
class AehardwareMcpModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // permite si clienti MCP din browser (ex. MCP Inspector); datele sunt publice, doar citire
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, MCP-Protocol-Version');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            $this->json(self::error(null, -32000, 'Foloseste POST (MCP Streamable HTTP, fara flux SSE)'), 405);
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || $body === []) {
            $this->json(self::error(null, -32700, 'Corpul cererii nu este JSON valid'), 400);
        }

        $server = new AeMcpServer(new AeCatalog($this->context), $this->context->link->getModuleLink('aehardware', 'builder'));
        if (array_is_list($body)) {
            $response = array_values(array_filter(array_map([$server, 'handle'], $body)));
        } else {
            $response = $server->handle($body);
        }

        if ($response === null || $response === []) {
            http_response_code(202); // doar notificari
            exit;
        }
        $this->json($response);
    }

    private function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
