<?php
/**
 * Textele magazinului (doar in romana), aplicate de sync.php la fiecare pornire.
 * Imaginile (ae-slide-*.png, ae-banner.png) sunt generate de build_seed.py.
 * Legaturile catre categorii se dau prin calea din categorii.csv.
 */

return [
    // ps_imageslider: in ordinea slide-urilor existente (tema afiseaza titlul si descrierea in stanga jos)
    'slider' => [
        [
            'image' => 'ae-slide-1.png',
            'title' => 'Componente PC',
            'description' => '<h3>Procesoare, placi video si memorii</h3><p>Alege piesele pentru urmatorul tau calculator.</p>',
            'category' => 'Componente PC',
        ],
        [
            'image' => 'ae-slide-2.png',
            'title' => 'Laptopuri si monitoare',
            'description' => '<h3>Pentru gaming si birou</h3><p>Laptopuri cu placi video RTX si monitoare de pana la 165 Hz.</p>',
            'category' => 'Laptopuri',
        ],
        [
            'image' => 'ae-slide-3.png',
            'title' => 'Periferice',
            'description' => '<h3>Mouse-uri, tastaturi si casti</h3><p>Accesorii cu fir si wireless pentru orice birou.</p>',
            'category' => 'Periferice',
        ],
    ],

    // ps_banner: imaginea de sub slider, duce la configurator (pagina modulului aehardware)
    'banner' => [
        'image' => 'ae-banner.png',
        'description' => 'Construieste-ti PC-ul: procesoare, placi video, memorii si SSD-uri',
        'page' => 'creaza-pc',
    ],

    // ps_mainmenu: legatura catre configurator, dupa categoriile principale
    'menu_builder' => ['label' => 'Creaza propriul tau PC', 'page' => 'creaza-pc'],

    // ps_customtext: blocul de text de pe pagina principala
    'customtext' => '<h2>De ce sa cumperi de la noi</h2>
<p><strong class="dark">Componente hardware verificate, livrate rapid in toata tara.</strong></p>
<p>Gasesti procesoare, placi video, placi de baza, memorii, SSD-uri, surse, carcase, laptopuri, monitoare si periferice
de la producatori cunoscuti. Fiecare produs are caracteristicile principale afisate, ca sa alegi usor piese compatibile:
socket-ul procesorului si al placii de baza, tipul memoriei sau puterea sursei.</p>',

    // ps_linklist: titlurile blocurilor din subsol (text englezesc din instalare => text romanesc)
    'linkblocks' => [
        'Products' => 'Produse',
        'Our company' => 'Compania noastra',
    ],

    // blockreassurance: blocul de garantii de pe pagina produsului (titlu englezesc => [titlu, descriere])
    'reassurance' => [
        'Security policy' => ['Plati securizate', 'Datele tale sunt transmise criptat, prin HTTPS'],
        'Delivery policy' => ['Livrare rapida', 'Expediem in 1-2 zile lucratoare, prin curier'],
        'Return policy' => ['Retur in 14 zile', 'Poti returna produsul in ambalajul original'],
    ],

    // texte salvate in configurare la instalare, ramase in engleza
    'config' => [
        // blockwishlist
        'blockwishlist_WishlistPageName' => 'Listele mele de dorinte',
        'blockwishlist_WishlistDefaultTitle' => 'Lista mea de dorinte',
        'blockwishlist_CreateButtonLabel' => 'Creeaza o lista noua',
        // psgdpr: bifa de acord de la crearea contului si din "Informatiile mele"
        'PSGDPR_CREATION_FORM' => 'Sunt de acord cu termenii si conditiile si cu politica de confidentialitate',
        'PSGDPR_CUSTOMER_FORM' => 'Sunt de acord cu termenii si conditiile si cu politica de confidentialitate',
        // ps_dataprivacy: textul de sub formularul de creare cont
        'CUSTPRIV_MSG_AUTH' => 'Datele personale pe care le introduci sunt folosite pentru a raspunde intrebarilor tale, '
            . 'pentru a procesa comenzile sau pentru a-ti da acces la anumite informatii. Le poti modifica si sterge oricand '
            . 'din pagina "Contul tau".',
        // pagina afisata cand magazinul este in mentenanta
        'PS_MAINTENANCE_TEXT' => 'Actualizam magazinul si revenim in curand. Multumim pentru rabdare!',
        // semnatura raspunsurilor trimise din Admin > Asistenta pentru clienti
        'PS_CUSTOMER_SERVICE_SIGNATURE' => "Stimate client,\n\nCu stima,\nEchipa de asistenta pentru clienti",
    ],

    // texte englezesti scrise direct in sabloanele unor module (fara traducere romaneasca in pachetul oficial):
    // sync.php copiaza sablonul in tema (themes/<tema>/modules/...) si inlocuieste textele (englezesc => romanesc).
    // Textele romanesti nu au voie sa contina apostrof (sunt in interiorul {l s='...'}).
    'templates' => [
        // ferestrele listelor de dorinte: butonul de inchidere (citit de cititoarele de ecran)
        'blockwishlist/views/templates/components/modals/*.tpl' => [
            'aria-label="Close"' => 'aria-label="Inchide"',
        ],
        // fereastra "Mai multe informatii" de la plata prin transfer bancar
        'ps_wirepayment/views/templates/hook/ps_wirepayment_intro.tpl' => [
            'aria-labelledby="Bankwire information"' => 'aria-label="Informatii transfer bancar"',
            'aria-label="Close"' => 'aria-label="Inchide"',
        ],
        // GDPR: legatura din "Contul tau" si pagina "Datele mele personale"
        'psgdpr/views/templates/front/customerAccount.tpl' => [
            'GDPR - Personal data' => 'GDPR - Datele mele personale',
        ],
        'psgdpr/views/templates/front/customerPersonalData.tpl' => [
            'Access to my data' => 'Accesul la datele mele',
            'At any time, you have the right to retrieve the data you have provided to our site. Click on "Get my data" to automatically download a copy of your personal data on a pdf or csv file.'
                => 'Oricand ai dreptul sa primesti datele pe care le-ai furnizat magazinului. Apasa unul dintre butoanele de mai jos ca sa descarci o copie a datelor tale personale, ca fisier PDF sau CSV.',
            'GET MY DATA TO CSV' => 'Descarca datele mele (CSV)',
            'GET MY DATA TO PDF' => 'Descarca datele mele (PDF)',
            'Rectification & Erasure requests' => 'Cereri de rectificare si stergere a datelor',
            'You have the right to modify all the personal information found in the "My Account" page. For any other request you might have regarding the rectification and/or erasure of your personal data, please contact us through our'
                => 'Poti modifica oricand datele personale din pagina "Contul tau". Pentru orice alta cerere privind rectificarea sau stergerea datelor tale personale, scrie-ne prin',
            'contact page' => 'pagina de contact',
            'We will review your request and reply as soon as possible.' => 'Vom analiza cererea si iti vom raspunde cat mai repede.',
        ],
    ],

    // transportatorii instalati cu magazinul (id_reference => [nume, termen de livrare]), afisati la finalizarea comenzii
    'carriers' => [
        1 => ['Ridicare personala', 'Ridica din magazin'],
        2 => ['Curier rapid', 'Livrare in 24 de ore!'],
        3 => ['Curier economic', 'Cumpara mai mult si platesti mai putin!'],
        4 => ['Curier colete usoare', 'Cu cat coletul e mai usor, cu atat livrarea e mai ieftina!'],
    ],

    // paginile CMS create la instalare (id => continut); titlurile romanesti raman cele din instalare
    'cms' => [
        1 => '<h2>Livrare si retur</h2>
<h3>Livrarea comenzii</h3>
<p>Comenzile se expediaza prin curier in 1-2 zile lucratoare de la confirmarea platii. Primesti pe e-mail numarul
de urmarire al coletului.</p>
<p>Costul livrarii se calculeaza in cos, in functie de greutatea coletului. Componentele sensibile (placi video,
placi de baza, procesoare) sunt ambalate in pungi antistatice si cutii protejate.</p>
<h3>Retur</h3>
<p>Poti returna produsele in 14 zile de la primire, in ambalajul original si fara urme de folosire.</p>',

        2 => '<h2>Notificare legala</h2>
<p>Acest magazin este un proiect demonstrativ realizat pentru disciplina Afaceri electronice. Produsele, preturile
si stocurile sunt fictive si sunt folosite doar pentru testarea platformei PrestaShop.</p>
<p>Numele de produse si marcile apartin proprietarilor lor. Imaginile produselor sunt desene generate automat.</p>',

        3 => '<h2>Termeni si conditii de utilizare</h2>
<h3>1. Comenzi</h3>
<p>Comenzile plasate pe acest site sunt de test si nu genereaza obligatii de livrare sau de plata.</p>
<h3>2. Preturi</h3>
<p>Preturile sunt afisate in lei si includ TVA de 21%.</p>
<h3>3. Garantie</h3>
<p>Toate produsele au garantie standard: 24 de luni pentru persoane fizice si 12 luni pentru persoane juridice.</p>',

        4 => '<h2>Despre noi</h2>
<p>Suntem un magazin online demonstrativ de componente hardware si echipamente IT.</p>
<p>Oferta este organizata pe categorii si subcategorii (procesoare AMD si Intel, placi video NVIDIA, AMD si Intel,
memorii DDR4 si DDR5, SSD-uri NVMe si SATA si altele), ca sa gasesti rapid piesele potrivite pentru calculatorul tau.</p>
<ul>
<li>Componente de la producatori cunoscuti</li>
<li>Caracteristici tehnice afisate pentru fiecare produs</li>
<li>Livrare rapida in toata tara</li>
</ul>',

        5 => '<h2>Plati securizate</h2>
<p>Poti plati prin transfer bancar sau online cu cardul. Datele tale sunt transmise criptat, prin conexiune HTTPS.</p>
<p>Fiind un magazin demonstrativ, nu se efectueaza plati reale.</p>',
    ],
];
