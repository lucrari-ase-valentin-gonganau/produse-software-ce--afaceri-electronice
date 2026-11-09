<?php
/**
 * Sincronizare la fiecare pornire a containerului (apelat din docker/10-sync.sh).
 * Poate rula de oricate ori: ce exista deja se actualizeaza, nu se dubleaza.
 *
 *  1. domeniu + SSL (din PS_DOMAIN / PS_ENABLE_SSL)
 *  2. doar limba romana (celelalte limbi dezactivate)
 *  3. moneda RON implicita (EUR/USD dezactivate)
 *  4. taxe RO din pachetul de localizare, cota standard 21%
 *  5. sterge produsele si categoriile demo
 *  6. arborele de categorii din /seed/categorii.csv, cu descrieri si imagini
 *  7. produsele din /seed/produse.csv (dupa "Reference #"), cu imagini din /seed/seed-img
 *  8. modulele aehardware (configurator PC, server MCP, chatbot) si aeplatacard (plata cu cardul) + meniul de sus
 *  9. logo, favicon, pagina principala, subsolul si paginile CMS in romana (/seed/continut.php)
 */

require '/var/www/html/config/config.inc.php';

const SEED_DIR = '/seed';
const REF_PREFIX = 'AE-';
const TAX_GROUP_STANDARD = 'RO Standard Rate (21%)';
const DEMO_CATEGORIES = ['clothes', 'men', 'women', 'accessories', 'stationery', 'home-accessories', 'art'];

$context = Context::getContext();
$context->employee = new Employee(1);
$idLang = 0; // limba romana, aflata la pasul 2
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

// ---------------------------------------------------------------- 2. doar limba romana

// Engleza ramane instalata (o cer unele module), dar dezactivata: dispare selectorul de limba
// si adresele nu mai au prefixul /ro/.
$idLang = (int) Language::getIdByIso('ro');
if (!$idLang) {
    fail('limba romana nu este instalata (PS_LANGUAGE=ro)');
}
Configuration::updateGlobalValue('PS_LANG_DEFAULT', $idLang);
foreach (Language::getLanguages(true, false, true) as $idOther) {
    if ((int) $idOther !== $idLang) {
        $other = new Language((int) $idOther);
        $other->active = 0;
        $other->update();
        out('limba ' . $other->iso_code . ' dezactivata');
    }
}
// tara vizitatorului ramane Romania: altfel un browser in engleza (en-US) e tratat ca vizitator
// din SUA si vede preturile fara TVA
Configuration::updateGlobalValue('PS_DETECT_COUNTRY', 0);

// ---------------------------------------------------------------- 3. moneda RON

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

// ---------------------------------------------------------------- 4. taxe

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

// ---------------------------------------------------------------- 5. date demo

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
// magazinele fizice demo (adrese din Miami, program in engleza) nu se mai afiseaza
$db->update('store', ['active' => 0], 'city = "Miami"');

if ($demoProducts || $demoCategories) {
    out('date demo sterse: ' . count($demoProducts) . ' produse, ' . count($demoCategories) . ' categorii');
}

// ---------------------------------------------------------------- 6. categorii

// amprenta imaginilor deja incarcate (produse si categorii), ca sa nu le refacem la fiecare pornire
$imageHashes = json_decode((string) Configuration::get('AE_SEED_IMAGES'), true) ?: [];

/** imaginea categoriei: originalul + miniaturile folosite de tema (category_default, small_default) */
function replaceCategoryImage($idCategory, $file)
{
    $base = _PS_CAT_IMG_DIR_ . (int) $idCategory;
    if (!ImageManager::resize($file, $base . '.jpg')) {
        return false;
    }
    foreach (ImageType::getImagesTypes('categories') as $type) {
        ImageManager::resize($file, $base . '-' . stripslashes($type['name']) . '.jpg', $type['width'], $type['height']);
    }

    return true;
}

$csv = fopen(SEED_DIR . '/categorii.csv', 'r');
if (!$csv) {
    fail('lipseste ' . SEED_DIR . '/categorii.csv');
}
fgetcsv($csv, 0, ';'); // antetul

$idRoot = (int) Configuration::get('PS_ROOT_CATEGORY');
$idHome = (int) Configuration::get('PS_HOME_CATEGORY');
$categories = []; // cale ("A > B > C") => id, in ordinea din CSV
$children = [];   // id parinte => id-urile copiilor, in ordinea din CSV

while (($row = fgetcsv($csv, 0, ';')) !== false) {
    if (count($row) < 3) {
        continue;
    }
    [$path, $description, $imageName] = $row;
    $names = explode(' > ', $path);
    $name = array_pop($names);
    $parentPath = implode(' > ', $names);
    if ($parentPath !== '' && !isset($categories[$parentPath])) {
        fail("categoria $path apare in CSV inaintea parintelui ei");
    }
    $idParent = $parentPath === '' ? $idHome : $categories[$parentPath];

    // cautam dupa link_rewrite in tot arborele: o categorie mutata sub alt parinte nu se dubleaza
    $rewrite = Tools::str2url($name);
    $idCategory = (int) $db->getValue(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
         JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . '
         WHERE c.id_category NOT IN (' . $idRoot . ', ' . $idHome . ') AND cl.link_rewrite = "' . pSQL($rewrite) . '"
         ORDER BY c.id_parent = ' . (int) $idParent . ' DESC, c.id_category'
    );

    $category = new Category($idCategory ?: null);
    $category->name = allLangs($name);
    $category->link_rewrite = allLangs($rewrite);
    $category->description = allLangs('<p>' . $description . '</p>');
    $category->id_parent = $idParent;
    $category->active = 1;
    $category->groupBox = array_column(Group::getGroups($idLang), 'id_group');
    if (!($idCategory ? $category->update() : $category->add())) {
        fail("nu am putut salva categoria $path");
    }
    $categories[$path] = (int) $category->id;
    $children[$idParent][] = (int) $category->id;

    $imageFile = SEED_DIR . '/seed-img/' . $imageName;
    if (is_file($imageFile)) {
        $hash = md5_file($imageFile);
        if (($imageHashes['cat:' . $path] ?? null) !== $hash || !is_file(_PS_CAT_IMG_DIR_ . $category->id . '.jpg')) {
            if (!replaceCategoryImage($category->id, $imageFile)) {
                fail("nu am putut incarca imaginea categoriei $path");
            }
            $imageHashes['cat:' . $path] = $hash;
        }
    }
}
fclose($csv);

// ordinea din CSV, in meniu si in lista de subcategorii
foreach ($children as $ids) {
    foreach ($ids as $position => $idCategory) {
        $db->update('category', ['position' => $position], 'id_category = ' . $idCategory);
        $db->update('category_shop', ['position' => $position], 'id_category = ' . $idCategory);
    }
}

// categoriile seed care nu mai sunt in CSV (cele create manual raman)
$previousCategories = json_decode((string) Configuration::get('AE_SEED_CATEGORIES'), true) ?: [];
foreach (array_diff($previousCategories, $categories) as $idOld) {
    $old = new Category((int) $idOld);
    if (Validate::isLoadedObject($old)) {
        $old->delete();
        out('categorie stearsa: ' . $old->name[$idLang]);
    }
}
Configuration::updateGlobalValue('AE_SEED_CATEGORIES', json_encode(array_values($categories)));
Category::regenerateEntireNtree();
out('categorii: ' . count($categories));

// ---------------------------------------------------------------- 7. produse

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

$seen = [];
$created = $updated = $images = 0;

while (($row = fgetcsv($csv, 0, ';')) !== false) {
    if (count($row) < 13) {
        continue;
    }
    [$active, $name, $categoryNames, $price, , $reference, $brand, $quantity, $summary, $description, $imageUrl, $imageAlt, $features] = $row;
    $seen[] = $reference;

    $idProduct = (int) $db->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"');
    $product = new Product($idProduct ?: null);

    // "Componente PC,Procesoare,Procesoare AMD": produsul apare in toate trei, implicita e ultima
    $productCategories = [];
    $path = [];
    foreach (explode(',', $categoryNames) as $categoryName) {
        $path[] = trim($categoryName);
        $key = implode(' > ', $path);
        if (!isset($categories[$key])) {
            fail("$reference: categoria $key lipseste din categorii.csv");
        }
        $productCategories[] = $categories[$key];
    }
    $idCategory = end($productCategories);

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

    $product->updateCategories($productCategories);
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

// caracteristici scoase din seed (TDP a devenit "Consum"), sterse doar daca niciun produs nu le mai foloseste
foreach (['TDP'] as $oldFeature) {
    $idFeature = (int) $db->getValue('SELECT id_feature FROM ' . _DB_PREFIX_ . 'feature_lang WHERE name = "' . pSQL($oldFeature) . '"');
    if ($idFeature && !$db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'feature_product WHERE id_feature = ' . $idFeature)) {
        (new Feature($idFeature))->delete();
        out("caracteristica $oldFeature stearsa");
    }
}

Configuration::updateGlobalValue('AE_SEED_IMAGES', json_encode($imageHashes));
out("produse: $created noi, $updated actualizate, " . count($removed) . " sterse, $images imagini refacute");

// ---------------------------------------------------------------- 8. modulul aehardware si meniul

// modulul (configurator PC, server MCP, chatbot) e copiat in modules/ de 10-sync.sh
$aeModule = Module::getInstanceByName('aehardware');
if (!$aeModule) {
    fail('lipseste modules/aehardware');
}
if (!Module::isInstalled('aehardware')) {
    if (!$aeModule->install()) {
        fail('nu am putut instala modulul aehardware: ' . implode(', ', $aeModule->getErrors()));
    }
    out('modulul aehardware instalat');
} elseif (!$aeModule->ensureInstalled()) {
    fail('nu am putut actualiza modulul aehardware');
}

// plata online cu cardul (procesator simulat), copiat tot de 10-sync.sh
$cardModule = Module::getInstanceByName('aeplatacard');
if (!$cardModule) {
    fail('lipseste modules/aeplatacard');
}
if (!Module::isInstalled('aeplatacard')) {
    if (!$cardModule->install()) {
        fail('nu am putut instala modulul aeplatacard: ' . implode(', ', $cardModule->getErrors()));
    }
    out('modulul aeplatacard instalat (plata cu cardul)');
} elseif (!$cardModule->ensureInstalled()) {
    fail('nu am putut actualiza modulul aeplatacard');
}

$content = require SEED_DIR . '/continut.php';

/** adresa absoluta a unei pagini a magazinului, ex. "creaza-pc" */
function shopUrl($path)
{
    return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . $path;
}

// legatura catre configurator in meniul de sus (creata o data, eticheta si adresa se actualizeaza mereu)
$idShop = (int) Configuration::get('PS_SHOP_DEFAULT');
$idMenuLink = (int) $db->getValue(
    'SELECT id_linksmenutop FROM ' . _DB_PREFIX_ . 'linksmenutop WHERE id_linksmenutop = ' . (int) Configuration::get('AE_MENU_BUILDER_LINK')
);
if (!$idMenuLink) {
    $db->insert('linksmenutop', ['new_window' => 0, 'id_shop' => $idShop]);
    $idMenuLink = (int) $db->Insert_ID();
    Configuration::updateGlobalValue('AE_MENU_BUILDER_LINK', $idMenuLink);
}
$db->delete('linksmenutop_lang', 'id_linksmenutop = ' . $idMenuLink);
foreach ($languages as $idLanguage) {
    $db->insert('linksmenutop_lang', [
        'id_linksmenutop' => $idMenuLink,
        'id_lang' => (int) $idLanguage,
        'id_shop' => $idShop,
        'label' => pSQL($content['menu_builder']['label']),
        'link' => pSQL(shopUrl($content['menu_builder']['page'])),
    ]);
}

// meniul de sus (ps_mainmenu): categoriile principale (subcategoriile apar in lista derulanta) + configuratorul
$menuItems = implode(',', array_merge(array_map(function ($idCategory) {
    return 'CAT' . $idCategory;
}, $children[$idHome] ?? []), ['LNK' . $idMenuLink]));
Configuration::updateGlobalValue('MOD_BLOCKTOPMENU_ITEMS', $menuItems);
out('meniu: ' . $menuItems);

// ---------------------------------------------------------------- 9. logo, pagina principala, subsol, CMS

/**
 * adresa absoluta a categoriei (ps_banner pune "http://" in fata adreselor relative);
 * PrestaShop redirectioneaza catre adresa prietenoasa, ex. /20-componente-pc
 */
function categoryUrl($path)
{
    global $categories;

    return isset($categories[$path])
        ? Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'index.php?controller=category&id_category=' . $categories[$path]
        : '';
}

/** copiaza o imagine generata in folderul unui modul */
function copyToModule($image, $moduleDir)
{
    if (!copy(SEED_DIR . '/seed-img/' . $image, _PS_MODULE_DIR_ . $moduleDir . '/' . $image)) {
        fail("nu am putut copia $image in modules/$moduleDir");
    }
}

// logo-ul din antet (si din e-mailuri) + favicon-ul; nume noi de fisier, ca browserele sa nu le ia din cache
foreach (['PS_LOGO' => 'ae-logo.png', 'PS_FAVICON' => 'ae-favicon.ico'] as $key => $image) {
    if (!copy(SEED_DIR . '/seed-img/' . $image, _PS_IMG_DIR_ . $image)) {
        fail("nu am putut copia $image in img/");
    }
    Configuration::updateGlobalValue($key, $image);
}

if (Module::isInstalled('ps_imageslider')) {
    $slides = $db->executeS('SELECT id_homeslider_slides FROM ' . _DB_PREFIX_ . 'homeslider_slides ORDER BY position');
    foreach ($content['slider'] as $i => $slide) {
        if (!isset($slides[$i])) {
            break;
        }
        copyToModule($slide['image'], 'ps_imageslider/images');
        $db->update('homeslider_slides_lang', [
            'title' => pSQL($slide['title']),
            'description' => pSQL($slide['description'], true),
            'legend' => pSQL($slide['title']),
            'url' => pSQL(categoryUrl($slide['category'])),
            'image' => pSQL($slide['image']),
        ], 'id_homeslider_slides = ' . (int) $slides[$i]['id_homeslider_slides']);
    }
    out('slider: ' . min(count($slides), count($content['slider'])) . ' slide-uri');
}

if (Module::isInstalled('ps_banner')) {
    copyToModule($content['banner']['image'], 'ps_banner/img');
    Configuration::updateValue('BANNER_IMG', allLangs($content['banner']['image']));
    Configuration::updateValue('BANNER_LINK', allLangs(shopUrl($content['banner']['page'])));
    Configuration::updateValue('BANNER_DESC', allLangs($content['banner']['description']));
}

// blocul "Furnizori" din stanga (ps_supplierlist): furnizorii sunt firmele de la care magazinul cumpara marfa;
// magazinul nu are furnizori definiti (doar marci), deci blocul ar fi mereu gol
if (Module::isEnabled('ps_supplierlist')) {
    Module::getInstanceByName('ps_supplierlist')->disable();
    out('blocul Furnizori ascuns (nu exista furnizori)');
}

if (Module::isInstalled('ps_customtext')) {
    $db->update('info_lang', ['text' => pSQL($content['customtext'], true)]);
}

if (Module::isInstalled('ps_linklist')) {
    foreach ($content['linkblocks'] as $english => $romanian) {
        $db->update('link_block_lang', ['name' => pSQL($romanian)], 'name = "' . pSQL($english) . '"');
    }
}

if (Module::isInstalled('blockreassurance')) {
    foreach ($content['reassurance'] as $english => [$title, $description]) {
        $db->update('psreassurance_lang', ['title' => pSQL($title), 'description' => pSQL($description)],
            'title = "' . pSQL($english) . '"');
    }
}

foreach ($content['config'] as $key => $value) {
    Configuration::updateValue($key, allLangs($value));
}

// sabloane de module cu texte englezesti scrise direct: copia din tema (themes/<tema>/modules/...) are
// prioritate fata de sablonul modulului; o refacem la fiecare pornire, cu textele inlocuite
$themeModules = _PS_ALL_THEMES_DIR_ . $context->shop->theme_name . '/modules/';
$overridden = 0;
foreach ($content['templates'] as $pattern => $replacements) {
    $missing = $replacements; // textele negasite in niciun fisier (ex. dupa o actualizare a modulului)
    foreach (glob(_PS_MODULE_DIR_ . $pattern) ?: [] as $template) {
        $html = file_get_contents($template);
        $found = array_filter(array_keys($replacements), function ($english) use ($html) {
            return strpos($html, $english) !== false;
        });
        if (!$found) {
            continue;
        }
        $missing = array_diff_key($missing, array_flip($found));
        $target = $themeModules . substr($template, strlen(_PS_MODULE_DIR_));
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            fail('nu am putut crea ' . dirname($target));
        }
        file_put_contents($target, strtr($html, $replacements));
        ++$overridden;
    }
    foreach (array_keys($missing) as $english) {
        out('ATENTIE: textul "' . Tools::substr($english, 0, 40) . '" nu mai exista in modules/' . $pattern);
    }
}

// transportatorii: numele (comun tuturor limbilor) si termenul de livrare; doar versiunea curenta (deleted = 0)
foreach ($content['carriers'] as $idReference => list($carrierName, $delay)) {
    $idCarrier = (int) $db->getValue('SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier
        WHERE id_reference = ' . (int) $idReference . ' AND deleted = 0');
    if ($idCarrier) {
        $db->update('carrier', ['name' => pSQL($carrierName)], 'id_carrier = ' . $idCarrier);
        $db->update('carrier_lang', ['delay' => pSQL($delay)], 'id_carrier = ' . $idCarrier);
    }
}

foreach ($content['cms'] as $idCms => $html) {
    $cms = new CMS((int) $idCms);
    if (Validate::isLoadedObject($cms)) {
        $cms->content = allLangs($html);
        $cms->update();
    }
}
out('logo, favicon si texte romanesti: slider, banner, bloc de text, garantii, subsol, GDPR, transportatori, '
    . count($content['cms']) . " pagini CMS, $overridden sabloane de module");

Tools::clearSmartyCache();
Media::clearCache();
