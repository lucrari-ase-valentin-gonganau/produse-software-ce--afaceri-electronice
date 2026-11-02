<?php
/**
 * Chatbotul: POST /module/aehardware/chat cu {"token": "...", "messages": [{"role": "user", "content": "..."}]}
 * Conversatia se pastreaza in browser (sessionStorage); aici se trimit doar ultimele mesaje.
 */
class AehardwareChatModuleFrontController extends ModuleFrontController
{
    const MAX_MESSAGES = 12;

    const MAX_LENGTH = 1000;

    public function postProcess()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['error' => 'Foloseste POST.'], 405);
        }
        if (!$this->module->chatAvailable()) {
            $this->json(['error' => 'Asistentul nu este activ.'], 503);
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || ($body['token'] ?? '') !== Tools::getToken(false)) {
            $this->json(['error' => 'Sesiunea a expirat. Reincarca pagina.'], 403);
        }

        $history = [];
        foreach (array_slice(is_array($body['messages'] ?? null) ? $body['messages'] : [], -self::MAX_MESSAGES) as $message) {
            $role = $message['role'] ?? '';
            $content = is_string($message['content'] ?? null) ? trim($message['content']) : '';
            if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
                // raspunsurile asistentului pot fi mai lungi decat intrebarile
                $history[] = ['role' => $role, 'content' => mb_substr($content, 0, $role === 'user' ? self::MAX_LENGTH : 4 * self::MAX_LENGTH)];
            }
        }
        if (!$history || end($history)['role'] !== 'user') {
            $this->json(['error' => 'Scrie o intrebare.'], 400);
        }
        if (!$this->module->allowChatMessage(Tools::getRemoteAddr())) {
            $this->json(['error' => 'Ai trimis prea multe mesaje. Incearca din nou peste o ora.'], 429);
        }

        try {
            $reply = $this->module->chatbot()->reply($history, $this->module->systemPrompt());
        } catch (Exception $e) {
            // detaliile (ex. cheie gresita) apar in Admin > Parametri avansati > Jurnale
            PrestaShopLogger::addLog('[aehardware] chatbot: ' . $e->getMessage(), 3);
            $this->json(['error' => 'Asistentul nu poate raspunde acum. Incearca din nou mai tarziu.'], 502);
        }

        $this->json(['reply' => $reply !== '' ? $reply : 'Nu am gasit un raspuns. Poti reformula intrebarea?']);
    }

    private function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
