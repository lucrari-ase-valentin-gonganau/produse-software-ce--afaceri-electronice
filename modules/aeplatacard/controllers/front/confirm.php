<?php
/**
 * Pasul 3: intoarcerea de la procesatorul de plati, cu rezultatul semnat.
 *  - platit:   comanda devine "Plata acceptata" si clientul ajunge pe pagina "Comanda confirmata";
 *  - neplatit: comanda devine "Eroare la plata", iar pagina ii permite clientului sa reincerce plata.
 */
class AeplatacardConfirmModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        $result = $this->module->readFields(AePlataCard::RESULT_FIELDS);
        $order = new Order((int) $result['comanda']);

        // rezultatul trebuie sa fie semnat de procesator si sa corespunda exact comenzii (referinta, suma)
        $valid = $this->module->verify($result)
            && Validate::isLoadedObject($order)
            && $order->module === $this->module->name
            && $order->reference === $result['referinta']
            && $result['suma'] === $this->module->amount($order)
            && in_array($result['stare'], ['platit', 'neplatit'], true);

        if ($valid) {
            $this->module->applyResult($order, $result['stare'] === 'platit', $result['tranzactie']);
            $order = new Order((int) $order->id);
            if ($order->hasBeenPaid()) {
                Tools::redirect($this->module->confirmationUrl($order));
            }
        }

        parent::initContent();
        $this->context->smarty->assign([
            'ae_valid' => $valid,
            'ae_reference' => $valid ? $order->reference : '',
            'ae_pay_url' => $valid && $this->module->canPay($order) ? $this->module->gatewayUrl($order) : null,
            'ae_history_url' => $this->context->link->getPageLink('history', true),
            'ae_contact_url' => $this->context->link->getPageLink('contact', true),
        ]);
        $this->setTemplate('module:aeplatacard/views/templates/front/failed.tpl');
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = 'Plata nu a fost efectuata';
        $page['meta']['robots'] = 'noindex';

        return $page;
    }
}
