<?php
/**
 * Pasul 2: pagina procesatorului de plati (simulator), deschisa din link-ul de plata.
 *
 * Ca un procesator extern, pagina foloseste doar campurile primite in link (verificate prin semnatura),
 * nu citeste comanda din baza de date. Butoanele "Platit" / "Neplatit" trimit formularul tot aici;
 * procesatorul semneaza rezultatul si il trimite inapoi la adresa de intoarcere a magazinului.
 */
class AeplatacardGatewayModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        $request = $this->module->readFields(AePlataCard::REQUEST_FIELDS);
        $valid = $this->module->verify($request);

        $choice = Tools::getValue('rezultat');
        if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($choice, ['platit', 'neplatit'], true)) {
            $paid = $choice === 'platit';
            $result = [
                'comanda' => $request['comanda'],
                'referinta' => $request['referinta'],
                'suma' => $request['suma'],
                'stare' => $choice,
                'tranzactie' => $paid ? 'SIM-' . strtoupper(bin2hex(random_bytes(6))) : '',
            ];
            $result['semnatura'] = $this->module->sign($result);
            $separator = strpos($request['intoarcere'], '?') === false ? '?' : '&';
            Tools::redirect($request['intoarcere'] . $separator . http_build_query($result));
        }

        // pagina procesatorului nu foloseste tema magazinului
        $this->context->smarty->assign([
            'ae_valid' => $valid,
            'ae_request' => $request,
            'ae_amount' => $valid ? $this->context->getCurrentLocale()->formatPrice((float) $request['suma'], $request['moneda']) : '',
        ]);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->module->fetch('module:aeplatacard/views/templates/front/gateway.tpl');
        exit;
    }
}
