<?php
/**
 * Citirea catalogului (doar produse si categorii active, fara date despre clienti).
 * Folosita de configuratorul PC, de serverul MCP si, prin MCP, de chatbot.
 */
class AeCatalog
{
    /** @var Context */
    private $context;

    /** @var int */
    private $idLang;

    /** @var int */
    private $idShop;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->idLang = (int) $context->language->id;
        $this->idShop = (int) $context->shop->id;
    }

    /**
     * Produsele active, cu pret (lei, cu TVA), stoc, link, imagine si caracteristici.
     *
     * @param array $filters categorie (nume sau link_rewrite, include subcategoriile), text (cuvinte cautate
     *                       in nume, marca, referinta si caracteristici), pret_maxim, doar_in_stoc, ids, referinte, limita
     */
    public function products(array $filters = [])
    {
        $where = ['ps.active = 1'];
        if (!empty($filters['ids'])) {
            $where[] = 'p.id_product IN (' . implode(',', array_map('intval', $filters['ids'])) . ')';
        }
        if (!empty($filters['referinte'])) {
            $where[] = 'p.reference IN ("' . implode('","', array_map('pSQL', $filters['referinte'])) . '")';
        }
        if (!empty($filters['categorie'])) {
            $idCategory = $this->categoryId($filters['categorie']);
            if (!$idCategory) {
                return [];
            }
            // produsele sunt asociate si categoriilor parinte (vezi docker/sync.php)
            $where[] = 'EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp
                WHERE cp.id_product = p.id_product AND cp.id_category = ' . $idCategory . ')';
        }
        if (!empty($filters['text'])) {
            foreach (preg_split('/\s+/', trim($filters['text'])) as $word) {
                $like = '"%' . pSQL(addcslashes($word, '%_')) . '%"';
                $where[] = '(pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . ' OR m.name LIKE ' . $like . '
                    OR EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'feature_product fp
                        JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fp.id_feature_value
                            AND fvl.id_lang = ' . $this->idLang . '
                        WHERE fp.id_product = p.id_product AND fvl.value LIKE ' . $like . '))';
            }
        }

        $rows = Db::getInstance()->executeS(
            'SELECT p.id_product, p.reference, pl.name, pl.link_rewrite, pl.description, m.name AS brand, cl.name AS category
             FROM ' . _DB_PREFIX_ . 'product p
             JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
             JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product
                AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
             LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON m.id_manufacturer = p.id_manufacturer
             LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = ps.id_category_default
                AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pl.name'
        ) ?: [];

        $features = $this->features(array_column($rows, 'id_product'));
        $products = [];
        foreach ($rows as $row) {
            $product = $this->format($row, $features[$row['id_product']] ?? []);
            if (isset($filters['pret_maxim']) && $filters['pret_maxim'] !== '' && $product['pret'] > (float) $filters['pret_maxim']) {
                continue;
            }
            if (!empty($filters['doar_in_stoc']) && $product['stoc'] <= 0) {
                continue;
            }
            $products[] = $product;
        }

        return empty($filters['limita']) ? $products : array_slice($products, 0, (int) $filters['limita']);
    }

    /** un produs dupa referinta (ex. AE-CPU-001), cu descrierea completa */
    public function product($reference)
    {
        $products = $this->products(['referinte' => [$reference]]);

        return $products ? $products[0] : null;
    }

    /** arborele de categorii active de sub Acasa, cu numarul de produse active din fiecare */
    public function categories()
    {
        $home = new Category((int) Configuration::get('PS_HOME_CATEGORY'));
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, c.id_parent, c.level_depth, cl.name, cl.link_rewrite,
                (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'category_product cp
                 JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = cp.id_product
                    AND ps.id_shop = ' . $this->idShop . ' AND ps.active = 1
                 WHERE cp.id_category = c.id_category) AS products
             FROM ' . _DB_PREFIX_ . 'category c
             JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
                AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . '
             WHERE c.active = 1 AND c.nleft > ' . (int) $home->nleft . ' AND c.nright < ' . (int) $home->nright . '
             ORDER BY c.nleft'
        ) ?: [];

        $paths = [];
        $categories = [];
        foreach ($rows as $row) {
            $path = isset($paths[$row['id_parent']]) ? $paths[$row['id_parent']] . ' > ' . $row['name'] : $row['name'];
            $paths[$row['id_category']] = $path;
            $categories[] = [
                'categorie' => $row['name'],
                'cale' => $path,
                'cod' => $row['link_rewrite'],
                'produse' => (int) $row['products'],
                'url' => $this->context->link->getCategoryLink((int) $row['id_category'], $row['link_rewrite']),
            ];
        }

        return $categories;
    }

    /** id-ul categoriei dupa nume ("Placi video") sau link_rewrite ("placi-video") */
    public function categoryId($nameOrCode)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
             JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cl.id_category AND c.active = 1
             WHERE cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . '
                AND (cl.link_rewrite = "' . pSQL(Tools::str2url($nameOrCode)) . '" OR cl.name = "' . pSQL($nameOrCode) . '")
             ORDER BY c.level_depth'
        );
    }

    /** imaginea mica a categoriei (sau null daca nu are) */
    public function categoryImage($code)
    {
        $idCategory = $this->categoryId($code);
        if (!$idCategory || !is_file(_PS_CAT_IMG_DIR_ . $idCategory . '.jpg')) {
            return null;
        }

        return $this->context->link->getCatImageLink(Tools::str2url($code), $idCategory, 'small_default');
    }

    /** id_product => [nume caracteristica => valoare], in ordinea caracteristicilor */
    private function features(array $ids)
    {
        if (!$ids) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT fp.id_product, fl.name, fvl.value
             FROM ' . _DB_PREFIX_ . 'feature_product fp
             JOIN ' . _DB_PREFIX_ . 'feature f ON f.id_feature = fp.id_feature
             JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = fp.id_feature AND fl.id_lang = ' . $this->idLang . '
             JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fp.id_feature_value
                AND fvl.id_lang = ' . $this->idLang . '
             WHERE fp.id_product IN (' . implode(',', array_map('intval', $ids)) . ')
             ORDER BY f.position'
        ) ?: [];

        $features = [];
        foreach ($rows as $row) {
            $features[$row['id_product']][$row['name']] = $row['value'];
        }

        return $features;
    }

    private function format(array $row, array $features)
    {
        $id = (int) $row['id_product'];
        $price = (float) Product::getPriceStatic($id, true, null, 2);
        $cover = Image::getCover($id);

        return [
            'id' => $id,
            'referinta' => $row['reference'],
            'nume' => $row['name'],
            'marca' => $row['brand'],
            'categorie' => $row['category'],
            'pret' => $price,
            'pret_text' => $this->formatPrice($price),
            'stoc' => (int) StockAvailable::getQuantityAvailableByProduct($id),
            'url' => $this->context->link->getProductLink($id, $row['link_rewrite']),
            'imagine' => $cover ? $this->context->link->getImageLink($row['link_rewrite'], $id . '-' . $cover['id_image'], 'home_default') : null,
            'caracteristici' => $features,
            'descriere' => trim(html_entity_decode(strip_tags(str_replace('</li>', "</li>\n", $row['description'])), ENT_QUOTES, 'UTF-8')),
        ];
    }

    public function formatPrice($price)
    {
        return $this->context->getCurrentLocale()->formatPrice($price, $this->context->currency->iso_code);
    }
}
