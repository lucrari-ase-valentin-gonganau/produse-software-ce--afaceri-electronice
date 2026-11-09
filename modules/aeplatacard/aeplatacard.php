<?php
/**
 * AE Plata card: metoda de plata "Card bancar (online)" cu un procesator de plati simulat.
 *
 * Fluxul, ca la un procesator real (Netopia, Stripe etc.):
 *  1. clientul alege plata cu cardul si plaseaza comanda (controllers/front/validation.php):
 *     comanda se creeaza cu starea "Se asteapta plata cu cardul";
 *  2. magazinul il trimite la link-ul de plata (controllers/front/gateway.php), cu detaliile comenzii
 *     (comerciant, comanda, suma, client) semnate HMAC-SHA256, ca sa nu poata fi modificate pe drum;
 *  3. pe pagina procesatorului clientul apasa "Platit" sau "Neplatit"; procesatorul il trimite inapoi
 *     in magazin (controllers/front/confirm.php) cu rezultatul semnat;
 *  4. magazinul verifica semnatura si schimba starea comenzii: "Plata acceptata" (cu id-ul tranzactiei)
 *     sau "Eroare la plata" (clientul poate reincerca din pagina de eroare sau din istoricul comenzilor).
 *
 * Simulatorul ruleaza in acelasi magazin, dar foloseste doar datele primite in link (ca un procesator extern).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class AePlataCard extends PaymentModule
{
    const HOOKS = ['paymentOptions', 'displayPaymentReturn', 'displayOrderDetail'];

    /** campurile cererii de plata (magazin -> procesator), toate semnate */
    const REQUEST_FIELDS = ['comerciant', 'comanda', 'referinta', 'suma', 'moneda', 'descriere', 'client', 'email', 'intoarcere', 'semnatura'];

    /** campurile rezultatului (procesator -> magazin), toate semnate */
    const RESULT_FIELDS = ['comanda', 'referinta', 'suma', 'stare', 'tranzactie', 'semnatura'];

    const STATE_NAME = 'Se asteapta plata cu cardul';

    public function __construct()
    {
        $this->name = 'aeplatacard';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'AE Hardware';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();

        $this->displayName = 'Card bancar (online)';
        $this->description = 'Plata online cu cardul printr-un procesator de plati simulat: butoanele Platit / Neplatit confirma sau resping plata comenzii.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    /** hookurile, starea comenzii si cheia de semnare; se poate rula de oricate ori (si la actualizari) */
    public function ensureInstalled()
    {
        if (!Configuration::get('AE_CARD_SECRET')) {
            Configuration::updateGlobalValue('AE_CARD_SECRET', bin2hex(random_bytes(32)));
        }

        return $this->registerHook(self::HOOKS) && $this->ensureOrderState();
    }

    /** starea "Se asteapta plata cu cardul" (ca "Se asteapta transferul bancar" de la ps_wirepayment) */
    private function ensureOrderState()
    {
        $state = new OrderState((int) Configuration::get('AE_CARD_OS_WAITING'));
        if (Validate::isLoadedObject($state) && !$state->deleted) {
            return true;
        }
        $state = new OrderState();
        $state->name = array_fill_keys(Language::getIDs(false), self::STATE_NAME);
        $state->module_name = $this->name;
        $state->color = '#4169E1';
        $state->unremovable = true;
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;
        $state->hidden = false;
        $state->delivery = false;
        $state->shipped = false;
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateGlobalValue('AE_CARD_OS_WAITING', (int) $state->id);
    }

    /** la fel ca la aehardware: fara fisiere de traducere, iar instalarea din linia de comanda nu are Symfony */
    public function updateModuleTranslations()
    {
        return true;
    }

    public function uninstall()
    {
        // starea ramane (comenzile vechi o folosesc), doar nu mai e legata de modul
        Configuration::deleteByName('AE_CARD_SECRET');

        return parent::uninstall();
    }

    // ------------------------------------------------------------------ checkout

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText('Plata online cu cardul')
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:' . $this->name . '/views/templates/hook/payment_intro.tpl'));

        return [$option];
    }

    private function checkCurrency(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        foreach ((array) $this->getCurrency((int) $cart->id_currency) as $allowed) {
            if ((int) $allowed['id_currency'] === (int) $currency->id) {
                return true;
            }
        }

        return false;
    }

    /** pe pagina "Comanda confirmata", dupa intoarcerea de la procesator */
    public function hookDisplayPaymentReturn($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $this->context->smarty->assign([
            'ae_card_paid' => $order->hasBeenPaid(),
            'ae_card_transaction' => $this->transactionId($order),
            'ae_card_total' => $this->context->getCurrentLocale()->formatPrice($order->total_paid, (new Currency($order->id_currency))->iso_code),
            'ae_card_reference' => $order->reference,
            'ae_card_pay_url' => $this->canPay($order) ? $this->gatewayUrl($order) : null,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/payment_return.tpl');
    }

    /** in istoricul comenzilor: butonul "Plateste acum" pentru o comanda cu cardul inca neplatita */
    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name || !$this->canPay($order)) {
            return '';
        }
        $this->context->smarty->assign('ae_card_pay_url', $this->gatewayUrl($order));

        return $this->fetch('module:' . $this->name . '/views/templates/hook/order_detail.tpl');
    }

    // ------------------------------------------------------------------ cererea de plata si rezultatul

    /** suma comenzii, in formatul trimis procesatorului (ex. 1234.50) */
    public function amount(Order $order)
    {
        return number_format((float) $order->total_paid, 2, '.', '');
    }

    /** comanda inca se poate plati: neplatita si neanulata */
    public function canPay(Order $order)
    {
        return !$order->hasBeenPaid() && (int) $order->current_state !== (int) Configuration::get('PS_OS_CANCELED');
    }

    /** link-ul de plata: pagina procesatorului, cu detaliile comenzii semnate */
    public function gatewayUrl(Order $order)
    {
        $customer = new Customer((int) $order->id_customer);
        $products = array_sum(array_column($order->getProducts(), 'product_quantity'));
        $request = [
            'comerciant' => Configuration::get('PS_SHOP_NAME'),
            'comanda' => (string) $order->id,
            'referinta' => $order->reference,
            'suma' => $this->amount($order),
            'moneda' => (new Currency((int) $order->id_currency))->iso_code,
            'descriere' => 'Comanda ' . $order->reference . ' (' . $products . ($products == 1 ? ' produs' : ' produse') . ')',
            'client' => trim($customer->firstname . ' ' . $customer->lastname),
            'email' => $customer->email,
            'intoarcere' => $this->context->link->getModuleLink($this->name, 'confirm', [], true),
        ];
        $request['semnatura'] = $this->sign($request);

        return $this->context->link->getModuleLink($this->name, 'gateway', $request, true);
    }

    /** semnatura HMAC-SHA256 a campurilor (fara campul semnatura), cu cheia secreta a magazinului */
    public function sign(array $fields)
    {
        unset($fields['semnatura']);
        ksort($fields);

        return hash_hmac('sha256', http_build_query($fields), (string) Configuration::get('AE_CARD_SECRET'));
    }

    public function verify(array $fields)
    {
        return $fields['semnatura'] !== '' && hash_equals($this->sign($fields), $fields['semnatura']);
    }

    /**
     * Campurile din cerere, exact cum au fost trimise (POST, apoi GET). Nu Tools::getValue: acesta
     * modifica unele caractere (ex. backslash) si semnatura n-ar mai corespunde.
     */
    public function readFields(array $names)
    {
        $fields = [];
        foreach ($names as $name) {
            $value = $_POST[$name] ?? $_GET[$name] ?? '';
            $fields[$name] = is_string($value) ? $value : '';
        }

        return $fields;
    }

    /**
     * Aplica rezultatul primit de la procesator. Doar o comanda inca neplatita se schimba: un rezultat
     * primit a doua oara (ex. reincarcarea paginii) nu mai modifica nimic.
     */
    public function applyResult(Order $order, $paid, $transaction)
    {
        if (!$this->canPay($order)) {
            return;
        }
        if (!$paid) {
            $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));

            return;
        }

        // produs fara stoc la plasarea comenzii: ramane "din stocul furnizorului", dar platita
        $state = (int) $order->current_state === (int) Configuration::get('PS_OS_OUTOFSTOCK_UNPAID')
            ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_PAYMENT';
        $order->setCurrentState((int) Configuration::get($state));

        // plata creata de PrestaShop la starea "platita" primeste id-ul tranzactiei (apare in admin, la comanda)
        foreach ($order->getOrderPaymentCollection() as $payment) {
            if (!$payment->transaction_id) {
                $payment->transaction_id = $transaction;
                $payment->card_brand = 'Card (simulator)';
                $payment->update();
            }
        }
    }

    public function transactionId(Order $order)
    {
        foreach ($order->getOrderPaymentCollection() as $payment) {
            if ($payment->transaction_id) {
                return $payment->transaction_id;
            }
        }

        return null;
    }

    /** pagina "Comanda confirmata" a comenzii */
    public function confirmationUrl(Order $order)
    {
        $customer = new Customer((int) $order->id_customer);

        return $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        ]);
    }

    // ------------------------------------------------------------------ admin

    public function getContent()
    {
        $state = new OrderState((int) Configuration::get('AE_CARD_OS_WAITING'), (int) $this->context->language->id);

        return '<div class="panel"><h3><i class="icon-credit-card"></i> Plata online cu cardul (simulator)</h3>'
            . '<p>La finalizarea comenzii clientul este trimis la pagina procesatorului de plati, cu detaliile comenzii '
            . 'semnate HMAC-SHA256. Acolo alege <strong>Platit</strong> sau <strong>Neplatit</strong> si se intoarce in magazin.</p>'
            . '<ul>'
            . '<li>Comanda noua: <strong>' . htmlspecialchars((string) $state->name, ENT_QUOTES, 'UTF-8') . '</strong></li>'
            . '<li>Platit: <strong>Plata acceptata</strong>, cu id-ul tranzactiei in blocul Plati al comenzii</li>'
            . '<li>Neplatit: <strong>Eroare la plata</strong>; clientul poate reincerca din istoricul comenzilor</li>'
            . '</ul><p>Modulul nu are setari. Nu se efectueaza plati reale.</p></div>';
    }
}
