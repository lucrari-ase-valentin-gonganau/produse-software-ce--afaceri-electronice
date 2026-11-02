<?php
/**
 * AE Hardware: configuratorul "Creaza propriul tau PC", serverul MCP al catalogului si chatbotul.
 *
 *  - /creaza-pc               configuratorul (controllers/front/builder.php), doar componente compatibile
 *  - /module/aehardware/mcp   serverul MCP, doar citire (controllers/front/mcp.php)
 *  - /module/aehardware/chat  chatbotul (controllers/front/chat.php): OpenAI + uneltele serverului MCP
 *
 * Setarile chatbotului (cheia OpenAI, modelul): Admin > Module > AE Hardware > Configureaza.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/AeCatalog.php';
require_once __DIR__ . '/classes/AeCompatibility.php';
require_once __DIR__ . '/classes/AeMcpServer.php';
require_once __DIR__ . '/classes/AeMcpClient.php';
require_once __DIR__ . '/classes/AeChatbot.php';

class AeHardware extends Module
{
    const HOOKS = ['moduleRoutes', 'actionFrontControllerSetMedia', 'displayBeforeBodyClosingTag', 'displayProductAdditionalInfo'];

    /** setarile si valorile lor implicite */
    const DEFAULTS = [
        'AE_CHAT_ENABLED' => 1,
        'AE_OPENAI_KEY' => '',
        'AE_OPENAI_MODEL' => 'gpt-4.1-mini',
        'AE_OPENAI_URL' => 'https://api.openai.com/v1',
        'AE_CHAT_LIMIT' => 20,
    ];

    public function __construct()
    {
        $this->name = 'aehardware';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'AE Hardware';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'AE Hardware: configurator PC si asistent';
        $this->description = 'Pagina "Creaza propriul tau PC" (doar componente compatibile), server MCP pentru catalog si chatbot OpenAI.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    /** hookurile, tabelul si setarile implicite; se poate rula de oricate ori (si la actualizari) */
    public function ensureInstalled()
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        // jurnalul mesajelor, pentru limita de mesaje pe ora / IP
        return $this->registerHook(self::HOOKS) && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ae_chat_log` (
                `id_ae_chat_log` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `ip` VARCHAR(45) NOT NULL,
                `date_add` DATETIME NOT NULL,
                KEY `ip_date` (`ip`, `date_add`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Modulul nu are fisiere de traducere (textele sunt in romana). Pasul standard are nevoie de
     * containerul Symfony, care lipseste cand modulul e instalat din linia de comanda (docker/sync.php).
     */
    public function updateModuleTranslations()
    {
        return true;
    }

    public function uninstall()
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ae_chat_log`');

        return parent::uninstall();
    }

    // ------------------------------------------------------------------ magazin

    /** adresa prietenoasa a configuratorului: /creaza-pc */
    public function hookModuleRoutes()
    {
        return [
            'module-aehardware-builder' => [
                'controller' => 'builder',
                'rule' => 'creaza-pc',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }

    public function hookActionFrontControllerSetMedia()
    {
        $this->context->controller->registerStylesheet('aehardware', 'modules/' . $this->name . '/views/css/aehardware.css', ['priority' => 200]);
        // meniul lateral de categorii: categoria curenta marcata si ramura ei deschisa
        $this->context->controller->registerJavascript('aehardware-categorytree', 'modules/' . $this->name . '/views/js/categorytree.js', ['priority' => 200]);
        if ($this->chatAvailable()) {
            $this->context->controller->registerJavascript('aehardware-chat', 'modules/' . $this->name . '/views/js/chat.js', ['priority' => 200]);
        }
    }

    /** butonul si fereastra chatbotului, pe toate paginile */
    public function hookDisplayBeforeBodyClosingTag()
    {
        if (!$this->chatAvailable()) {
            return '';
        }
        $this->context->smarty->assign([
            'ae_chat_url' => $this->context->link->getModuleLink($this->name, 'chat'),
            'ae_chat_token' => Tools::getToken(false),
            'ae_builder_url' => $this->context->link->getModuleLink($this->name, 'builder'),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/chat.tpl');
    }

    /** pe pagina unei componente de PC: "Construieste un PC cu acest produs" */
    public function hookDisplayProductAdditionalInfo($params)
    {
        $idProduct = (int) ($params['product']['id_product'] ?? 0);
        if (!$idProduct) {
            return '';
        }
        $catalog = new AeCatalog($this->context);
        $categories = Product::getProductCategories($idProduct);
        foreach (AeCompatibility::SLOTS as list($label, $code)) {
            if (in_array($catalog->categoryId($code), $categories)) {
                $this->context->smarty->assign('ae_builder_start_url', $this->context->link->getModuleLink($this->name, 'builder', ['start' => $idProduct]));

                return $this->fetch('module:' . $this->name . '/views/templates/hook/product.tpl');
            }
        }

        return '';
    }

    public function chatAvailable()
    {
        return Configuration::get('AE_CHAT_ENABLED') && Configuration::get('AE_OPENAI_KEY');
    }

    public function chatbot()
    {
        return new AeChatbot(
            Configuration::get('AE_OPENAI_KEY'),
            Configuration::get('AE_OPENAI_MODEL'),
            Configuration::get('AE_OPENAI_URL'),
            AeMcpClient::forShop()
        );
    }

    public function systemPrompt()
    {
        $shop = Configuration::get('PS_SHOP_NAME');
        $builder = $this->context->link->getModuleLink($this->name, 'builder');

        return "Esti asistentul virtual al magazinului online $shop, care vinde componente hardware si echipamente IT. "
            . "Raspunzi doar in limba romana, scurt si la obiect.\n"
            . "Reguli:\n"
            . "- Pentru orice informatie despre produse (ce exista, preturi, stoc, caracteristici, compatibilitate) foloseste uneltele; "
            . "nu inventa produse, preturi, stocuri sau caracteristici.\n"
            . "- Preturile sunt in lei, cu TVA inclus. Spune cand un produs nu este in stoc.\n"
            . "- Cand mentionezi un produs, pune linkul lui in format Markdown: [nume produs](url).\n"
            . "- Pentru compatibilitatea componentelor foloseste produse_compatibile sau verifica_configuratie.\n"
            . "- Pentru un calculator complet recomanda si configuratorul [Creaza propriul tau PC]($builder), "
            . "care afiseaza la fiecare pas doar componentele compatibile.\n"
            . "- Daca intrebarea nu are legatura cu magazinul, spune politicos ca poti ajuta doar cu produsele magazinului.\n"
            . '- Magazinul este demonstrativ (proiect pentru disciplina Afaceri electronice); preturile si stocurile sunt fictive.';
    }

    /** limita de mesaje pe ora pentru un IP (protejeaza cheia OpenAI); inregistreaza mesajul daca e permis */
    public function allowChatMessage($ip)
    {
        $db = Db::getInstance();
        $db->delete('ae_chat_log', 'date_add < "' . date('Y-m-d H:i:s', time() - 86400) . '"');
        $count = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ae_chat_log`
             WHERE ip = "' . pSQL($ip) . '" AND date_add > "' . date('Y-m-d H:i:s', time() - 3600) . '"'
        );
        if ($count >= (int) Configuration::get('AE_CHAT_LIMIT')) {
            return false;
        }

        return $db->insert('ae_chat_log', ['ip' => pSQL($ip), 'date_add' => date('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------ admin

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitAeHardware') || Tools::isSubmit('submitAeHardwareTest')) {
            $output .= $this->saveSettings();
        }
        if (Tools::isSubmit('submitAeHardwareTest')) {
            $output .= $this->testConnections();
        }

        return $output . $this->renderInfo() . $this->renderForm();
    }

    private function saveSettings()
    {
        $model = trim(Tools::getValue('AE_OPENAI_MODEL'));
        $url = trim(Tools::getValue('AE_OPENAI_URL'));
        if ($model === '' || !Validate::isAbsoluteUrl($url)) {
            return $this->displayError('Modelul si adresa API sunt obligatorii (adresa: ex. https://api.openai.com/v1).');
        }
        Configuration::updateValue('AE_CHAT_ENABLED', (int) Tools::getValue('AE_CHAT_ENABLED'));
        Configuration::updateValue('AE_OPENAI_MODEL', $model);
        Configuration::updateValue('AE_OPENAI_URL', $url);
        Configuration::updateValue('AE_CHAT_LIMIT', max(1, (int) Tools::getValue('AE_CHAT_LIMIT')));
        // campul cheii e gol la afisare: o cheie noua se salveaza doar daca e completata
        if ($key = trim(Tools::getValue('AE_OPENAI_KEY'))) {
            Configuration::updateValue('AE_OPENAI_KEY', $key);
        }
        if (Tools::getValue('AE_OPENAI_KEY_DELETE')) {
            Configuration::updateValue('AE_OPENAI_KEY', '');
        }

        return $this->displayConfirmation('Setarile au fost salvate.');
    }

    private function testConnections()
    {
        $output = '';
        try {
            $tools = AeMcpClient::forShop()->listTools();
            $output .= $this->displayConfirmation('Serverul MCP raspunde: ' . count($tools) . ' unelte ('
                . implode(', ', array_column($tools, 'name')) . ').');
        } catch (Exception $e) {
            $output .= $this->displayError('Serverul MCP: ' . $e->getMessage());
        }

        if (!Configuration::get('AE_OPENAI_KEY')) {
            return $output . $this->displayWarning('Nu este salvata nicio cheie OpenAI: chatbotul nu apare in magazin.');
        }
        try {
            $model = Configuration::get('AE_OPENAI_MODEL');
            $output .= $this->chatbot()->testKey()
                ? $this->displayConfirmation("Cheia OpenAI este valida, iar modelul $model este disponibil.")
                : $this->displayWarning("Cheia OpenAI este valida, dar modelul $model nu apare in lista de modele a contului.");
        } catch (Exception $e) {
            $output .= $this->displayError('OpenAI: ' . $e->getMessage());
        }

        return $output;
    }

    private function renderInfo()
    {
        $builder = $this->context->link->getModuleLink($this->name, 'builder');
        $mcp = $this->context->link->getModuleLink($this->name, 'mcp');
        $server = new AeMcpServer(new AeCatalog($this->context), $builder);
        $e = function ($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

        return '<div class="panel"><h3><i class="icon-info-circle"></i> AE Hardware</h3>'
            . '<p><strong>Configurator PC:</strong> <a href="' . $e($builder) . '" target="_blank">' . $e($builder) . '</a></p>'
            . '<p><strong>Server MCP</strong> (doar citire, catalogul de produse): <code>' . $e($mcp) . '</code>, transport Streamable HTTP. '
            . 'Il poti incerca si din <code>npx @modelcontextprotocol/inspector</code>.</p>'
            . '<p><strong>Unelte MCP:</strong> ' . $e(implode(', ', array_column($server->tools(), 'name'))) . '</p>'
            . '<p><strong>Chatbot:</strong> ' . ($this->chatAvailable() ? 'activ in magazin' : 'inactiv (activeaza-l si salveaza o cheie OpenAI)')
            . '. Chatbotul afla produsele doar prin serverul MCP.</p></div>';
    }

    private function renderForm()
    {
        $key = Configuration::get('AE_OPENAI_KEY');
        $form = ['form' => [
            'legend' => ['title' => 'Chatbot (OpenAI)', 'icon' => 'icon-comments'],
            'input' => [
                [
                    'type' => 'switch',
                    'label' => 'Chatbot activ',
                    'name' => 'AE_CHAT_ENABLED',
                    'is_bool' => true,
                    'values' => [['id' => 'on', 'value' => 1, 'label' => 'Da'], ['id' => 'off', 'value' => 0, 'label' => 'Nu']],
                ],
                [
                    'type' => 'password',
                    'label' => 'Cheie API OpenAI',
                    'name' => 'AE_OPENAI_KEY',
                    'desc' => $key ? 'Cheie salvata: ' . substr($key, 0, 7) . '...' . substr($key, -4) . '. Completeaza doar ca s-o schimbi.'
                        : 'Nicio cheie salvata. Cheia se creeaza pe platform.openai.com > API keys.',
                ],
                [
                    'type' => 'switch',
                    'label' => 'Sterge cheia salvata',
                    'name' => 'AE_OPENAI_KEY_DELETE',
                    'is_bool' => true,
                    'values' => [['id' => 'del_on', 'value' => 1, 'label' => 'Da'], ['id' => 'del_off', 'value' => 0, 'label' => 'Nu']],
                ],
                [
                    'type' => 'text',
                    'label' => 'Model',
                    'name' => 'AE_OPENAI_MODEL',
                    'desc' => 'Un model cu suport pentru unelte (function calling), ex. gpt-4.1-mini.',
                ],
                [
                    'type' => 'text',
                    'label' => 'Adresa API',
                    'name' => 'AE_OPENAI_URL',
                    'desc' => 'Implicit https://api.openai.com/v1. Merge si orice API compatibil OpenAI (/chat/completions).',
                ],
                [
                    'type' => 'text',
                    'label' => 'Mesaje pe ora / vizitator',
                    'name' => 'AE_CHAT_LIMIT',
                    'class' => 'fixed-width-sm',
                    'desc' => 'Limita de mesaje pe ora pentru o adresa IP, ca sa nu se consume cheia.',
                ],
            ],
            'submit' => ['title' => 'Salveaza', 'name' => 'submitAeHardware'],
            'buttons' => [[
                'type' => 'submit',
                'name' => 'submitAeHardwareTest',
                'title' => 'Salveaza si testeaza conexiunea',
                'icon' => 'process-icon-refresh',
            ]],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitAeHardware';
        $helper->fields_value = [
            'AE_CHAT_ENABLED' => (int) Configuration::get('AE_CHAT_ENABLED'),
            'AE_OPENAI_KEY' => '',
            'AE_OPENAI_KEY_DELETE' => 0,
            'AE_OPENAI_MODEL' => Configuration::get('AE_OPENAI_MODEL'),
            'AE_OPENAI_URL' => Configuration::get('AE_OPENAI_URL'),
            'AE_CHAT_LIMIT' => (int) Configuration::get('AE_CHAT_LIMIT'),
        ];

        return $helper->generateForm([$form]);
    }
}
