<?php
/**
 * Pasul 1: clientul a ales plata cu cardul si a plasat comanda.
 * Comanda se creeaza cu starea "Se asteapta plata cu cardul", apoi clientul merge la link-ul de plata.
 * (aceleasi verificari ca la ps_wirepayment)
 */
class AeplatacardValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // metoda de plata poate sa nu mai fie disponibila (ex. clientul a schimbat adresa chiar inainte)
        if (!in_array($this->module->name, array_column(Module::getPaymentModules(), 'name'))) {
            exit('Plata cu cardul nu este disponibila pentru aceasta comanda.');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('AE_CARD_OS_WAITING'),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName,
            null,
            [],
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        Tools::redirect($this->module->gatewayUrl(new Order((int) $this->module->currentOrder)));
    }
}
