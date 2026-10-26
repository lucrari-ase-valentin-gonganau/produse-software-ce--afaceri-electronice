<?php
/**
 * Sincronizare la fiecare pornire a containerului (apelat din docker/10-sync.sh).
 * Poate rula de oricate ori: ce exista deja se actualizeaza, nu se dubleaza.
 *
 *  1. domeniu + SSL (din PS_DOMAIN / PS_ENABLE_SSL)
 *  2. moneda RON implicita (EUR/USD dezactivate)
 *  3. taxe RO din pachetul de localizare, cota standard 21%
 *  4. sterge produsele si categoriile demo
 *  5. produsele din /seed/produse.csv (dupa "Reference #"), cu imagini din /seed/seed-img
 */

require '/var/www/html/config/config.inc.php';

const SEED_DIR = '/seed';
const REF_PREFIX = 'AE-';
const TAX_GROUP_STANDARD = 'RO Standard Rate (21%)';
const DEMO_CATEGORIES = ['clothes', 'men', 'women', 'accessories', 'stationery', 'home-accessories', 'art'];

$context = Context::getContext();
$context->employee = new Employee(1);
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$languages = Language::getIDs(false);
$db = Db::getInstance();

function out($msg)
{
    echo "  [sync] $msg\n";
}

function fail($msg)
{
    out("EROARE: $msg");
    exit(1);
}

/** acelasi text pentru toate limbile instalate */
function allLangs($value)
{
    global $languages;

    return array_fill_keys($languages, $value);
}

// ---------------------------------------------------------------- 1. domeniu + SSL

$domain = getenv('PS_DOMAIN');
$ssl = getenv('PS_ENABLE_SSL') === '1';
if ($domain) {
    $db->update('shop_url', ['domain' => pSQL($domain), 'domain_ssl' => pSQL($domain)], 'main = 1');
    Configuration::updateGlobalValue('PS_SHOP_DOMAIN', $domain);
    Configuration::updateGlobalValue('PS_SHOP_DOMAIN_SSL', $domain);
}
Configuration::updateGlobalValue('PS_SSL_ENABLED', $ssl ? 1 : 0);
Configuration::updateGlobalValue('PS_SSL_ENABLED_EVERYWHERE', $ssl ? 1 : 0);
if ($shopName = getenv('PS_SHOP_NAME')) {
    Configuration::updateGlobalValue('PS_SHOP_NAME', $shopName);
}
Tools::generateHtaccess();
out('domeniu: ' . ($domain ?: '-') . ', SSL: ' . ($ssl ? 'da' : 'nu'));

// ---------------------------------------------------------------- 2. moneda RON

// Installerul din linia de comanda nu poate adauga moneda din pachetul de localizare
// (are nevoie de containerul Symfony), asa ca o cream direct.
$idRon = (int) Currency::getIdByIsoCode('RON', 0, true);
if (!$idRon) {
    $ron = new Currency();
    $ron->iso_code = 'RON';
    $ron->numeric_iso_code = '946';
    $ron->precision = 2;
    $ron->conversion_rate = 1;
    $ron->active = 1;
    $ron->name = allLangs('leu românesc');
    $ron->symbol = allLangs('lei');
    $ron->pattern = allLangs('');
    if (!$ron->add()) {
        fail('nu am putut adauga moneda RON');
    }
    PaymentModule::addCurrencyPermissions($ron->id);
    $idRon = (int) $ron->id;
    out('moneda RON adaugata');
}

$ron = new Currency($idRon);
if (!$ron->active || (float) $ron->conversion_rate !== 1.0) {
    $ron->active = 1;
    $ron->conversion_rate = 1;
    $ron->update();
}
Configuration::updateGlobalValue('PS_CURRENCY_DEFAULT', $idRon);
foreach (Currency::getCurrencies(false, true) as $currency) {
    if ((int) $currency['id_currency'] !== $idRon) {
        $other = new Currency((int) $currency['id_currency']);
        $other->active = 0;
        $other->update();
        out('moneda ' . $other->iso_code . ' dezactivata');
    }
}

// ---------------------------------------------------------------- 3. taxe

$idTaxGroup = (int) TaxRulesGroup::getIdByName(TAX_GROUP_STANDARD);
if (!$idTaxGroup) {
    $pack = new LocalizationPack();
    $xml = simplexml_load_file(_PS_ROOT_DIR_ . '/localization/ro.xml');
    if (!$xml || !$pack->loadLocalisationPack($xml, ['taxes'], true)) {
        fail('nu am putut incarca taxele din localization/ro.xml');
    }

    // cota standard RO: 19% -> 21% din 1 august 2025
    $tax = new Tax((int) Tax::getTaxIdByName('TVA RO 19%'));
    $tax->name = allLangs('TVA RO 21%');
    $tax->rate = 21;
    $tax->update();

    $group = new TaxRulesGroup((int) TaxRulesGroup::getIdByName('RO Standard Rate (19%)'));
    $group->name = TAX_GROUP_STANDARD;
    $group->update();
    $idTaxGroup = (int) $group->id;
    out('taxe RO incarcate, cota standard 21%');
}

// ---------------------------------------------------------------- 4. date demo

$demoProducts = $db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference LIKE "demo\_%"');
foreach ($demoProducts as $row) {
    (new Product((int) $row['id_product']))->delete();
}

$demoCategories = $db->executeS(
    'SELECT DISTINCT c.id_category FROM ' . _DB_PREFIX_ . 'category c
     JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
     WHERE c.id_category > 2 AND cl.link_rewrite IN ("' . implode('","', DEMO_CATEGORIES) . '")
     ORDER BY c.level_depth DESC'
);
foreach ($demoCategories as $row) {
    $category = new Category((int) $row['id_category']);
    if (Validate::isLoadedObject($category)) {
        $category->delete();
    }
}
if ($demoProducts || $demoCategories) {
    out('date demo sterse: ' . count($demoProducts) . ' produse, ' . count($demoCategories) . ' categorii');
}

// ---------------------------------------------------------------- 5. produse

/** id-urile categoriilor folosite pana acum, in ordinea din CSV */
function categoryIds()
{
    return array_values(categoryId());
}

function categoryId($name = null)
{
    global $db, $idLang;
    static $cache = [];

    if ($name === null) {
        return $cache;
    }
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $idHome = (int) Configuration::get('PS_HOME_CATEGORY');
    $rewrite = Tools::str2url($name);
    $id = (int) $db->getValue(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
         JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . '
         WHERE c.id_parent = ' . $idHome . ' AND cl.link_rewrite = "' . pSQL($rewrite) . '"'
    );
    if (!$id) {
        $category = new Category();
        $category->name = allLangs($name);
        $category->link_rewrite = allLangs($rewrite);
        $category->id_parent = $idHome;
        $category->active = 1;
        $category->groupBox = array_column(Group::getGroups($idLang), 'id_group');
        if (!$category->add()) {
            fail("nu am putut crea categoria $name");
        }
        $id = (int) $category->id;
    }

    return $cache[$name] = $id;
}

function manufacturerId($name)
{
    $id = (int) Manufacturer::getIdByName($name);
    if (!$id) {
        $manufacturer = new Manufacturer();
        $manufacturer->name = $name;
        $manufacturer->active = 1;
        if (!$manufacturer->add()) {
            fail("nu am putut crea marca $name");
        }
        $id = (int) $manufacturer->id;
    }

    return $id;
}

function replaceImage(Product $product, $file, $legend)
{
    $product->deleteImages();

    $image = new Image();
    $image->id_product = (int) $product->id;
    $image->position = Image::getHighestPosition($product->id) + 1;
    $image->cover = 1;
    $image->legend = allLangs(Tools::substr($legend, 0, 128));
    if (!$image->add()) {
        return false;
    }

    $base = $image->getPathForCreation();
    if (!ImageManager::resize($file, $base . '.jpg')) {
        $image->delete();

        return false;
    }
    foreach (ImageType::getImagesTypes('products') as $type) {
        ImageManager::resize($file, $base . '-' . stripslashes($type['name']) . '.jpg', $type['width'], $type['height']);
    }

    return true;
}

$csv = fopen(SEED_DIR . '/produse.csv', 'r');
if (!$csv) {
    fail('lipseste ' . SEED_DIR . '/produse.csv');
}
fgetcsv($csv, 0, ';'); // antetul

// amprenta imaginilor deja incarcate, ca sa nu le refacem la fiecare pornire
$imageHashes = json_decode((string) Configuration::get('AE_SEED_IMAGES'), true) ?: [];
$seen = [];
$created = $updated = $images = 0;

while (($row = fgetcsv($csv, 0, ';')) !== false) {
    if (count($row) < 13) {
        continue;
    }
    [$active, $name, $categoryName, $price, , $reference, $brand, $quantity, $summary, $description, $imageUrl, $imageAlt, $features] = $row;
    $seen[] = $reference;

    $idProduct = (int) $db->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"');
    $product = new Product($idProduct ?: null);
    $idCategory = categoryId($categoryName);

    $product->reference = $reference;
    $product->name = allLangs($name);
    $product->link_rewrite = allLangs(Tools::str2url($name));
    $product->description_short = allLangs($summary);
    $product->description = allLangs($description);
    $product->id_category_default = $idCategory;
    $product->id_manufacturer = manufacturerId($brand);
    $product->id_tax_rules_group = $idTaxGroup;
    $product->price = (float) $price;
    $product->active = (int) $active;
    $product->visibility = 'both';
    $product->condition = 'new';
    $product->available_for_order = 1;
    $product->show_price = 1;

    if (!($idProduct ? $product->update() : $product->add())) {
        fail("nu am putut salva produsul $reference");
    }
    $idProduct ? $updated++ : $created++;

    $product->updateCategories([$idCategory]);
    StockAvailable::setQuantity($product->id, 0, (int) $quantity);

    $product->deleteFeatures();
    foreach (array_filter(explode(',', $features)) as $feature) {
        [$featureName, $featureValue, $position] = array_pad(explode(':', $feature), 3, false);
        $idFeature = (int) Feature::addFeatureImport($featureName, $position);
        $idFeatureValue = (int) FeatureValue::addFeatureValueImport($idFeature, $featureValue, $product->id, $idLang);
        Product::addFeatureProductImport($product->id, $idFeature, $idFeatureValue);
    }

    $imageFile = SEED_DIR . '/seed-img/' . basename($imageUrl);
    if (is_file($imageFile)) {
        $hash = md5_file($imageFile);
        if (($imageHashes[$reference] ?? null) !== $hash || !Image::getCover($product->id)) {
            if (!replaceImage($product, $imageFile, $imageAlt)) {
                fail("nu am putut incarca imaginea pentru $reference");
            }
            $imageHashes[$reference] = $hash;
            $images++;
        }
    }

    Search::indexation(false, $product->id);
}
fclose($csv);

// produsele seed care nu mai sunt in CSV (cele adaugate manual, fara prefixul AE-, raman)
$removed = $db->executeS(
    'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product
     WHERE reference LIKE "' . pSQL(REF_PREFIX) . '%"
     AND reference NOT IN ("' . implode('","', array_map('pSQL', $seen)) . '")'
);
foreach ($removed as $row) {
    (new Product((int) $row['id_product']))->delete();
    unset($imageHashes[$row['reference']]);
}

Configuration::updateGlobalValue('AE_SEED_IMAGES', json_encode($imageHashes));
out("produse: $created noi, $updated actualizate, " . count($removed) . " sterse, $images imagini refacute");

// meniul de sus (ps_mainmenu): categoriile din CSV, in ordinea lor (inlocuieste categoriile demo sterse)
$menuItems = implode(',', array_map(function ($idCategory) {
    return 'CAT' . $idCategory;
}, categoryIds()));
Configuration::updateGlobalValue('MOD_BLOCKTOPMENU_ITEMS', $menuItems);
out('meniu: ' . $menuItems);

Tools::clearSmartyCache();
Media::clearCache();
