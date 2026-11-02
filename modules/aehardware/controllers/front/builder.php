<?php
/**
 * Pagina "Creaza propriul tau PC": /creaza-pc
 * Pagina se construieste in browser (views/js/builder.js), din starea primita de la
 * /creaza-pc?ajax=1&selectie={"procesor": 25, ...}: pentru fiecare componenta, produsul ales
 * si doar produsele compatibile cu celelalte componente alese (AeCompatibility).
 */
class AehardwareBuilderModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        if (Tools::getValue('ajax')) {
            $selection = json_decode((string) Tools::getValue('selectie', '{}'), true);
            $selection = is_array($selection) ? $selection : [];
            $compatibility = new AeCompatibility(new AeCatalog($this->context));
            $selection = $this->applyStart($compatibility, $selection, (int) Tools::getValue('start'));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($compatibility->builderState($selection), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        parent::initContent();
        $this->context->smarty->assign([
            'ae_builder_url' => $this->context->link->getModuleLink('aehardware', 'builder'),
            'ae_start' => (int) Tools::getValue('start'),
        ]);
        $this->setTemplate('module:aehardware/views/templates/front/builder.tpl');
    }

    /**
     * "Construieste un PC cu acest produs": produsul intra in componenta lui, iar componentele alese
     * inainte care nu se potrivesc cu el se scot din selectie.
     */
    private function applyStart(AeCompatibility $compatibility, array $selection, $idProduct)
    {
        $slot = $idProduct ? $compatibility->slotOf($idProduct) : null;
        if (!$slot) {
            return $selection;
        }
        $selection[$slot] = $idProduct;
        $products = $compatibility->resolve($selection);
        foreach ($products as $other => $product) {
            if ($other !== $slot && $compatibility->problems([$slot => $products[$slot], $other => $product])) {
                unset($selection[$other]);
            }
        }

        return $selection;
    }

    public function setMedia()
    {
        parent::setMedia();
        $this->registerJavascript('aehardware-builder', 'modules/aehardware/views/js/builder.js', ['priority' => 200]);
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = 'Creaza propriul tau PC';
        $page['meta']['description'] = 'Configureaza un calculator din componente compatibile: procesor, placa de baza, memorie, placa video, SSD, sursa si carcasa.';

        return $page;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = ['title' => 'Creaza propriul tau PC', 'url' => $this->context->link->getModuleLink('aehardware', 'builder')];

        return $breadcrumb;
    }
}
