import csv, random, os, textwrap
from PIL import Image, ImageDraw, ImageFont
from desene_hardware import deseneaza, mix

# folderul de iesire: implicit langa script, in Docker se trimite SEED_OUT=/seed
OUT = os.environ.get("SEED_OUT") or os.path.dirname(os.path.abspath(__file__))
IMG_DIR = os.path.join(OUT, "seed-img")
os.makedirs(IMG_DIR, exist_ok=True)
IMG_BASE = "http://localhost/seed-img/"

# categorie: (cod, culoare, [nume caracteristici])
# caracteristicile folosite de configuratorul PC (modules/aehardware/classes/AeCompatibility.php):
# Socket, Memorie suportata, Tip memorie (placa de baza), Tip (RAM), Format, Placi de baza suportate (carcasa),
# Consum (fiecare componenta, in W) si Putere (sursa); valorile multiple se despart cu " / "
CATS = {
    "Procesoare": ("CPU", (198, 40, 40), ["Socket", "Nuclee", "Fire", "Consum", "Memorie suportata"]),
    "Placi video": ("GPU", (46, 125, 50), ["Chipset", "Memorie video", "Tip memorie", "Consum"]),
    "Placi de baza": ("MB", (21, 101, 192), ["Socket", "Chipset", "Format", "Tip memorie", "Consum"]),
    "Memorii RAM": ("RAM", (106, 27, 154), ["Capacitate", "Tip", "Frecventa", "Consum"]),
    "SSD": ("SSD", (0, 131, 143), ["Capacitate", "Interfata", "Format", "Consum"]),
    "Surse": ("PSU", (239, 108, 0), ["Putere", "Certificare", "Modulara"]),
    "Carcase": ("CASE", (69, 90, 100), ["Format", "Panou lateral", "Placi de baza suportate", "Consum"]),
    "Monitoare": ("MON", (40, 53, 147), ["Diagonala", "Rezolutie", "Rata refresh", "Panou"]),
    "Laptopuri": ("LAP", (55, 71, 79), ["Procesor", "Memorie RAM", "Stocare", "Diagonala"]),
    "Periferice": ("PER", (173, 20, 87), ["Tip", "Conectivitate"]),
}

# desenul folosit in imaginea produsului, pe grupe (SSD si Periferice se aleg dupa tip, vezi desen_produs)
DESEN = {
    "Procesoare": "cpu", "Placi video": "gpu", "Placi de baza": "mb", "Memorii RAM": "ram",
    "Surse": "psu", "Carcase": "case", "Monitoare": "monitor", "Laptopuri": "laptop",
}

# arborele de categorii al magazinului: (cale de la radacina, descriere, desen pentru imaginea categoriei)
# ordinea de aici este si ordinea din meniu
CATEGORII = [
    (("Componente PC",), "Tot ce iti trebuie ca sa construiesti sau sa imbunatatesti un calculator: procesoare, placi video, placi de baza, memorii, SSD-uri, surse si carcase.", "mb"),
    (("Componente PC", "Procesoare"), "Procesoare AMD Ryzen si Intel Core pentru calculatoare de birou, gaming si statii de lucru.", "cpu"),
    (("Componente PC", "Procesoare", "Procesoare AMD"), "Procesoare AMD Ryzen pentru placi de baza cu socket AM4 si AM5.", "cpu"),
    (("Componente PC", "Procesoare", "Procesoare Intel"), "Procesoare Intel Core si Core Ultra pentru placi de baza cu socket LGA1700 si LGA1851.", "cpu"),
    (("Componente PC", "Placi video"), "Placi video pentru gaming, editare video si aplicatii grafice.", "gpu"),
    (("Componente PC", "Placi video", "Placi video NVIDIA GeForce"), "Placi video cu procesor grafic NVIDIA GeForce RTX.", "gpu"),
    (("Componente PC", "Placi video", "Placi video AMD Radeon"), "Placi video cu procesor grafic AMD Radeon RX.", "gpu"),
    (("Componente PC", "Placi video", "Placi video Intel Arc"), "Placi video cu procesor grafic Intel Arc.", "gpu"),
    (("Componente PC", "Placi de baza"), "Placi de baza ATX si Micro-ATX pentru procesoare AMD si Intel.", "mb"),
    (("Componente PC", "Placi de baza", "Placi de baza AMD"), "Placi de baza cu socket AM4 si AM5, pentru procesoare AMD Ryzen.", "mb"),
    (("Componente PC", "Placi de baza", "Placi de baza Intel"), "Placi de baza cu socket LGA1700 si LGA1851, pentru procesoare Intel.", "mb"),
    (("Componente PC", "Memorii RAM"), "Kituri de memorie RAM DDR4 si DDR5 pentru calculatoare desktop.", "ram"),
    (("Componente PC", "Memorii RAM", "Memorii DDR4"), "Memorii DDR4, pentru platformele AM4 si placile Intel compatibile.", "ram"),
    (("Componente PC", "Memorii RAM", "Memorii DDR5"), "Memorii DDR5, pentru platformele AM5, LGA1700 si LGA1851.", "ram"),
    (("Componente PC", "SSD"), "Unitati SSD rapide pentru sistemul de operare, jocuri si fisiere.", "ssd_m2"),
    (("Componente PC", "SSD", "SSD NVMe"), "SSD-uri M.2 NVMe pe PCIe 4.0 si PCIe 5.0, cele mai rapide unitati de stocare.", "ssd_m2"),
    (("Componente PC", "SSD", "SSD SATA"), "SSD-uri SATA de 2.5 inch, potrivite si pentru calculatoare mai vechi.", "ssd_sata"),
    (("Componente PC", "Surse"), "Surse de alimentare cu certificare 80 PLUS, modulare si nemodulare.", "psu"),
    (("Componente PC", "Carcase"), "Carcase Mid Tower si Mini Tower, cu panou lateral din sticla sau acrilic.", "case"),
    (("Laptopuri",), "Laptopuri pentru gaming, birou si studiu.", "laptop"),
    (("Laptopuri", "Laptopuri gaming"), "Laptopuri cu placi video NVIDIA GeForce RTX, pentru jocuri si aplicatii grele.", "laptop"),
    (("Laptopuri", "Laptopuri office"), "Laptopuri usoare pentru birou, studiu si folosire zilnica.", "laptop"),
    (("Monitoare",), "Monitoare pentru gaming si birou, de la Full HD la 4K.", "monitor"),
    (("Monitoare", "Monitoare gaming"), "Monitoare cu rata de refresh de 144 Hz sau mai mare, pentru jocuri.", "monitor"),
    (("Monitoare", "Monitoare office"), "Monitoare pentru birou si lucru cu documente, la rezolutie mare.", "monitor"),
    (("Periferice",), "Mouse-uri, tastaturi, casti si camere web.", "keyboard"),
    (("Periferice", "Mouse"), "Mouse-uri cu fir si wireless, pentru gaming si birou.", "mouse"),
    (("Periferice", "Tastaturi"), "Tastaturi mecanice si de birou, cu fir sau wireless.", "keyboard"),
    (("Periferice", "Casti"), "Casti pentru gaming si apeluri, cu fir sau wireless.", "headset"),
    (("Periferice", "Camere web"), "Camere web pentru videoconferinte si streaming.", "webcam"),
]

# (categorie, brand, nume, pret cu TVA RON (orientativ), [valori caracteristici])
P = [
 # Consum = puterea maxima a componentei, in W; suma lor (+ rezerva) da puterea minima a sursei
 ("Procesoare","AMD","AMD Ryzen 5 7600",899,["AM5","6","12","88 W","DDR5"]),
 ("Procesoare","AMD","AMD Ryzen 7 7800X3D",1999,["AM5","8","16","162 W","DDR5"]),
 ("Procesoare","AMD","AMD Ryzen 9 7950X",2599,["AM5","16","32","230 W","DDR5"]),
 ("Procesoare","AMD","AMD Ryzen 5 5600",499,["AM4","6","12","76 W","DDR4"]),
 ("Procesoare","AMD","AMD Ryzen 7 9700X",1599,["AM5","8","16","88 W","DDR5"]),
 ("Procesoare","Intel","Intel Core i5-14600K",1299,["LGA1700","14","20","181 W","DDR4 / DDR5"]),
 ("Procesoare","Intel","Intel Core i7-14700K",1899,["LGA1700","20","28","253 W","DDR4 / DDR5"]),
 ("Procesoare","Intel","Intel Core i9-14900K",2599,["LGA1700","24","32","253 W","DDR4 / DDR5"]),
 ("Procesoare","Intel","Intel Core i5-12400F",549,["LGA1700","6","12","117 W","DDR4 / DDR5"]),
 ("Procesoare","Intel","Intel Core Ultra 7 265K",1899,["LGA1851","20","20","250 W","DDR5"]),

 ("Placi video","Gigabyte","Gigabyte GeForce RTX 4060 Eagle OC 8GB",1499,["NVIDIA GeForce RTX 4060","8 GB","GDDR6","115 W"]),
 ("Placi video","ASUS","ASUS Dual GeForce RTX 4060 Ti 8GB",1999,["NVIDIA GeForce RTX 4060 Ti","8 GB","GDDR6","160 W"]),
 ("Placi video","MSI","MSI GeForce RTX 5070 Ventus 2X OC 12GB",3199,["NVIDIA GeForce RTX 5070","12 GB","GDDR7","250 W"]),
 ("Placi video","Gigabyte","Gigabyte GeForce RTX 4070 Ti Super Gaming OC 16GB",4299,["NVIDIA GeForce RTX 4070 Ti Super","16 GB","GDDR6X","285 W"]),
 ("Placi video","ASUS","ASUS TUF Gaming GeForce RTX 5080 16GB",6499,["NVIDIA GeForce RTX 5080","16 GB","GDDR7","360 W"]),
 ("Placi video","Sapphire","Sapphire Pulse Radeon RX 7600 8GB",1299,["AMD Radeon RX 7600","8 GB","GDDR6","165 W"]),
 ("Placi video","Sapphire","Sapphire Pulse Radeon RX 7700 XT 12GB",2199,["AMD Radeon RX 7700 XT","12 GB","GDDR6","245 W"]),
 ("Placi video","PowerColor","PowerColor Hellhound Radeon RX 7800 XT 16GB",2699,["AMD Radeon RX 7800 XT","16 GB","GDDR6","263 W"]),
 ("Placi video","XFX","XFX Speedster MERC 310 Radeon RX 7900 XTX 24GB",4999,["AMD Radeon RX 7900 XTX","24 GB","GDDR6","355 W"]),
 ("Placi video","ASRock","ASRock Intel Arc B580 Steel Legend 12GB",1499,["Intel Arc B580","12 GB","GDDR6","190 W"]),

 ("Placi de baza","MSI","MSI B650 Gaming Plus WiFi",899,["AM5","B650","ATX","DDR5","50 W"]),
 ("Placi de baza","ASUS","ASUS TUF Gaming B650-Plus WiFi",999,["AM5","B650","ATX","DDR5","50 W"]),
 ("Placi de baza","Gigabyte","Gigabyte X670 Aorus Elite AX",1399,["AM5","X670","ATX","DDR5","65 W"]),
 ("Placi de baza","ASRock","ASRock B650M Pro RS",649,["AM5","B650","Micro-ATX","DDR5","40 W"]),
 ("Placi de baza","MSI","MSI MAG B550 Tomahawk",699,["AM4","B550","ATX","DDR4","45 W"]),
 ("Placi de baza","ASUS","ASUS Prime B760M-A WiFi D4",699,["LGA1700","B760","Micro-ATX","DDR4","35 W"]),
 ("Placi de baza","MSI","MSI PRO Z790-P WiFi",999,["LGA1700","Z790","ATX","DDR5","55 W"]),
 ("Placi de baza","Gigabyte","Gigabyte B760 Gaming X AX",799,["LGA1700","B760","ATX","DDR5","45 W"]),
 ("Placi de baza","ASUS","ASUS ROG Strix Z790-E Gaming WiFi",2399,["LGA1700","Z790","ATX","DDR5","70 W"]),
 ("Placi de baza","MSI","MSI MAG Z890 Tomahawk WiFi",1699,["LGA1851","Z890","ATX","DDR5","60 W"]),

 ("Memorii RAM","Kingston","Kingston FURY Beast 16GB (2x8GB) DDR4 3200MHz",229,["16 GB","DDR4","3200 MHz","6 W"]),
 ("Memorii RAM","Corsair","Corsair Vengeance LPX 32GB (2x16GB) DDR4 3600MHz",399,["32 GB","DDR4","3600 MHz","8 W"]),
 ("Memorii RAM","Kingston","Kingston FURY Beast 32GB (2x16GB) DDR5 6000MHz",549,["32 GB","DDR5","6000 MHz","10 W"]),
 ("Memorii RAM","G.Skill","G.Skill Trident Z5 RGB 32GB (2x16GB) DDR5 6400MHz",699,["32 GB","DDR5","6400 MHz","12 W"]),
 ("Memorii RAM","Corsair","Corsair Vengeance RGB 64GB (2x32GB) DDR5 6000MHz",1199,["64 GB","DDR5","6000 MHz","14 W"]),
 ("Memorii RAM","Kingston","Kingston FURY Beast 64GB (2x32GB) DDR5 5600MHz",999,["64 GB","DDR5","5600 MHz","12 W"]),
 ("Memorii RAM","Crucial","Crucial Pro 32GB (2x16GB) DDR5 5600MHz",479,["32 GB","DDR5","5600 MHz","9 W"]),
 ("Memorii RAM","G.Skill","G.Skill Ripjaws V 16GB (2x8GB) DDR4 3600MHz",249,["16 GB","DDR4","3600 MHz","6 W"]),
 ("Memorii RAM","TeamGroup","TeamGroup T-Force Delta RGB 32GB (2x16GB) DDR5 6000MHz",599,["32 GB","DDR5","6000 MHz","12 W"]),
 ("Memorii RAM","Kingston","Kingston FURY Renegade 32GB (2x16GB) DDR5 6400MHz",749,["32 GB","DDR5","6400 MHz","11 W"]),

 ("SSD","Samsung","Samsung 990 PRO 1TB NVMe",649,["1 TB","PCIe 4.0 NVMe","M.2 2280","8 W"]),
 ("SSD","Samsung","Samsung 990 PRO 2TB NVMe",1099,["2 TB","PCIe 4.0 NVMe","M.2 2280","8 W"]),
 ("SSD","WD","WD Black SN850X 1TB NVMe",549,["1 TB","PCIe 4.0 NVMe","M.2 2280","7 W"]),
 ("SSD","WD","WD Blue SN580 1TB NVMe",349,["1 TB","PCIe 4.0 NVMe","M.2 2280","5 W"]),
 ("SSD","Kingston","Kingston A400 960GB SATA",249,["960 GB","SATA III","2.5 inch","3 W"]),
 ("SSD","Crucial","Crucial T500 2TB NVMe",799,["2 TB","PCIe 4.0 NVMe","M.2 2280","7 W"]),
 ("SSD","Crucial","Crucial MX500 1TB SATA",349,["1 TB","SATA III","2.5 inch","4 W"]),
 ("SSD","Samsung","Samsung 870 EVO 1TB SATA",449,["1 TB","SATA III","2.5 inch","4 W"]),
 ("SSD","Kingston","Kingston KC3000 2TB NVMe",849,["2 TB","PCIe 4.0 NVMe","M.2 2280","9 W"]),
 ("SSD","Crucial","Crucial T700 2TB NVMe",1499,["2 TB","PCIe 5.0 NVMe","M.2 2280","12 W"]),

 ("Surse","Corsair","Corsair RM750e 750W",549,["750 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","Corsair","Corsair RM850x 850W",749,["850 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","Seasonic","Seasonic Focus GX-750 750W",599,["750 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","be quiet!","be quiet! Pure Power 12 M 850W",699,["850 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","MSI","MSI MAG A650BN 650W",299,["650 W","80 PLUS Bronze","Nemodulara"]),
 ("Surse","Seasonic","Seasonic Prime TX-1000 1000W",1699,["1000 W","80 PLUS Titanium","Complet modulara"]),
 ("Surse","Cooler Master","Cooler Master MWE Gold 850 V2 850W",549,["850 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","Corsair","Corsair CX650 650W",329,["650 W","80 PLUS Bronze","Nemodulara"]),
 ("Surse","Thermaltake","Thermaltake Toughpower GF3 1000W",999,["1000 W","80 PLUS Gold","Complet modulara"]),
 ("Surse","be quiet!","be quiet! Straight Power 12 1000W",1299,["1000 W","80 PLUS Platinum","Complet modulara"]),

 # Consum carcasa = ventilatoarele (si iluminarea RGB) incluse
 ("Carcase","NZXT","NZXT H5 Flow",449,["Mid Tower","Sticla securizata","ATX / Micro-ATX","6 W"]),
 ("Carcase","Fractal Design","Fractal Design North TG",749,["Mid Tower","Sticla securizata","ATX / Micro-ATX","6 W"]),
 ("Carcase","Lian Li","Lian Li O11 Dynamic EVO",799,["Mid Tower","Sticla securizata","ATX / Micro-ATX","0 W"]),
 ("Carcase","Corsair","Corsair 4000D Airflow",499,["Mid Tower","Sticla securizata","ATX / Micro-ATX","6 W"]),
 ("Carcase","be quiet!","be quiet! Pure Base 500DX",549,["Mid Tower","Sticla securizata","ATX / Micro-ATX","10 W"]),
 ("Carcase","Phanteks","Phanteks XT Pro",349,["Mid Tower","Sticla securizata","ATX / Micro-ATX","8 W"]),
 ("Carcase","Cooler Master","Cooler Master MasterBox Q300L",229,["Mini Tower","Acrilic","Micro-ATX","3 W"]),
 ("Carcase","Montech","Montech Air 903 Max",449,["Mid Tower","Sticla securizata","ATX / Micro-ATX","12 W"]),
 ("Carcase","DeepCool","DeepCool CH560",449,["Mid Tower","Sticla securizata","ATX / Micro-ATX","10 W"]),
 ("Carcase","Fractal Design","Fractal Design Pop Air",399,["Mid Tower","Sticla securizata","ATX / Micro-ATX","9 W"]),

 ("Monitoare","Dell","Dell S2722DGM 27 inch QHD 165Hz",1199,["27 inch","2560x1440","165 Hz","VA"]),
 ("Monitoare","LG","LG UltraGear 27GP850-B 27 inch QHD",1599,["27 inch","2560x1440","165 Hz","Nano IPS"]),
 ("Monitoare","Samsung","Samsung Odyssey G5 LS27CG510 27 inch QHD",999,["27 inch","2560x1440","165 Hz","VA"]),
 ("Monitoare","AOC","AOC 24G2SPU 23.8 inch FHD 165Hz",699,["23.8 inch","1920x1080","165 Hz","IPS"]),
 ("Monitoare","ASUS","ASUS TUF Gaming VG27AQ 27 inch QHD",1299,["27 inch","2560x1440","165 Hz","IPS"]),
 ("Monitoare","Dell","Dell P2723DE 27 inch QHD",1499,["27 inch","2560x1440","60 Hz","IPS"]),
 ("Monitoare","LG","LG 27UL500-W 27 inch 4K",1199,["27 inch","3840x2160","60 Hz","IPS"]),
 ("Monitoare","MSI","MSI MAG 274QRF-QD 27 inch QHD",1399,["27 inch","2560x1440","165 Hz","Rapid IPS"]),
 ("Monitoare","Samsung","Samsung Odyssey G7 LS32BG700 32 inch 4K",2999,["32 inch","3840x2160","144 Hz","IPS"]),
 ("Monitoare","Philips","Philips 24E1N1100A 23.8 inch FHD 100Hz",449,["23.8 inch","1920x1080","100 Hz","IPS"]),

 ("Laptopuri","Lenovo","Lenovo IdeaPad Slim 3 15 - Core i5 16GB 512GB",2799,["Intel Core i5-13420H","16 GB","512 GB SSD","15.6 inch"]),
 ("Laptopuri","ASUS","ASUS Vivobook 15 - Core i5 16GB 512GB",2499,["Intel Core i5-1235U","16 GB","512 GB SSD","15.6 inch"]),
 ("Laptopuri","Acer","Acer Nitro V 15 - RTX 4050 16GB 512GB",3999,["Intel Core i5-13420H","16 GB","512 GB SSD","15.6 inch"]),
 ("Laptopuri","Lenovo","Lenovo LOQ 15 - RTX 4060 16GB 1TB",4999,["AMD Ryzen 7 7435HS","16 GB","1 TB SSD","15.6 inch"]),
 ("Laptopuri","ASUS","ASUS TUF Gaming A15 - RTX 4060 16GB 1TB",5299,["AMD Ryzen 7 7435HS","16 GB","1 TB SSD","15.6 inch"]),
 ("Laptopuri","HP","HP Victus 16 - RTX 4050 16GB 512GB",4499,["Intel Core i5-13500H","16 GB","512 GB SSD","16.1 inch"]),
 ("Laptopuri","Apple","Apple MacBook Air 13 M4 16GB 256GB",5499,["Apple M4","16 GB","256 GB SSD","13.6 inch"]),
 ("Laptopuri","Dell","Dell Latitude 5450 - Core Ultra 5 16GB 512GB",5499,["Intel Core Ultra 5 135U","16 GB","512 GB SSD","14 inch"]),
 ("Laptopuri","Lenovo","Lenovo ThinkPad E14 Gen 6 - Core Ultra 5 16GB 512GB",4299,["Intel Core Ultra 5 125U","16 GB","512 GB SSD","14 inch"]),
 ("Laptopuri","ASUS","ASUS ROG Strix G16 - RTX 4070 16GB 1TB",7999,["Intel Core i7-14650HX","16 GB","1 TB SSD","16 inch"]),

 ("Periferice","Logitech","Logitech G502 X",299,["Mouse","Cu fir"]),
 ("Periferice","Logitech","Logitech MX Master 3S",449,["Mouse","Wireless si Bluetooth"]),
 ("Periferice","Razer","Razer DeathAdder V3",349,["Mouse","Cu fir"]),
 ("Periferice","Logitech","Logitech G Pro X Superlight 2",699,["Mouse","Wireless"]),
 ("Periferice","Keychron","Keychron K2 V2",449,["Tastatura mecanica","Bluetooth si cu fir"]),
 ("Periferice","Logitech","Logitech MX Keys S",549,["Tastatura","Wireless si Bluetooth"]),
 ("Periferice","HyperX","HyperX Alloy Origins Core",399,["Tastatura mecanica","Cu fir"]),
 ("Periferice","HyperX","HyperX Cloud III",449,["Casti","Cu fir"]),
 ("Periferice","Logitech","Logitech G435",299,["Casti","Wireless si Bluetooth"]),
 ("Periferice","Logitech","Logitech C920 HD Pro",299,["Camera web","USB"]),
]
assert len(P) == 100

CAI = {cale for cale, _, _ in CATEGORII}


def cale_categorie(grup, brand, nume, val):
    """calea produsului in arbore, de la radacina pana la subcategorie (dupa grupa si caracteristici)"""
    if grup == "Procesoare":
        sub = f"Procesoare {brand}"
    elif grup == "Placi video":
        sub = {"NVIDIA": "Placi video NVIDIA GeForce", "AMD": "Placi video AMD Radeon",
               "Intel": "Placi video Intel Arc"}[val[0].split()[0]]
    elif grup == "Placi de baza":
        sub = "Placi de baza AMD" if val[0].startswith("AM") else "Placi de baza Intel"
    elif grup == "Memorii RAM":
        sub = f"Memorii {val[1]}"
    elif grup == "SSD":
        sub = "SSD NVMe" if "NVMe" in val[1] else "SSD SATA"
    elif grup == "Monitoare":
        sub = "Monitoare gaming" if int(val[2].split()[0]) >= 144 else "Monitoare office"
    elif grup == "Laptopuri":
        sub = "Laptopuri gaming" if "RTX" in nume else "Laptopuri office"
    elif grup == "Periferice":
        sub = {"Mouse": "Mouse", "Casti": "Casti", "Camera web": "Camere web"}.get(val[0], "Tastaturi")
    else:
        sub = None  # Surse, Carcase: fara subcategorii
    radacina = () if grup in ("Laptopuri", "Monitoare", "Periferice") else ("Componente PC",)
    cale = radacina + (grup,) + ((sub,) if sub else ())
    assert cale in CAI, f"{nume}: categoria {cale} lipseste din CATEGORII"
    return cale


def desen_produs(grup, val):
    if grup == "SSD":
        return "ssd_m2" if "NVMe" in val[1] else "ssd_sata"
    if grup == "Periferice":
        return {"Mouse": "mouse", "Casti": "headset", "Camera web": "webcam"}.get(val[0], "keyboard")
    return DESEN[grup]


# subcategoriile pe marci au culoarea marcii, ca imaginile lor sa se deosebeasca
CULORI_MARCI = {"AMD": (198, 40, 40), "Intel": (0, 104, 181), "NVIDIA": (98, 150, 0)}


def culoare(cale):
    """culoarea categoriei: a marcii (Procesoare Intel), altfel a grupei (Componente PC: gri inchis)"""
    for marca, c in CULORI_MARCI.items():
        if marca in cale[-1].split():
            return c
    for nume in reversed(cale):
        if nume in CATS:
            return CATS[nume][1]
    return (38, 50, 56)

F_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
F_REG = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

BG = (246, 247, 249)
TILE = (238, 241, 245)


def font(bold, size):
    return ImageFont.truetype(F_BOLD if bold else F_REG, size)


def draw_image(path, cat, brand, name, feats, kind, color):
    """imaginea produsului: desenul componentei + marca, modelul si caracteristicile"""
    im = Image.new("RGB", (800, 800), BG)
    d = ImageDraw.Draw(im)
    # doar o linie subtire sus: in liste, coltul din stanga sus e acoperit de eticheta "Nou"
    d.rectangle([0, 0, 800, 14], fill=color)
    d.rounded_rectangle([120, 60, 680, 500], radius=36, fill="white")
    deseneaza(d, kind, (150, 70, 650, 490), color, (255, 255, 255))
    d.text((400, 556), brand, font=font(True, 46), fill=color, anchor="mm")
    model = name[len(brand):].strip() if name.startswith(brand) else name
    for i, ln in enumerate(textwrap.wrap(model, 32)[:2]):
        d.text((400, 614 + i * 44), ln, font=font(True, 32), fill=(33, 33, 33), anchor="mm")
    specs = "  ·  ".join(feats)
    size = 24
    while size > 16 and font(False, size).getlength(specs) > 740:
        size -= 1
    d.text((400, 726), specs, font=font(False, size), fill=(90, 90, 90), anchor="mm")
    d.text((400, 782), "Imagine demonstrativa - proiect Afaceri electronice",
           font=font(False, 16), fill=(150, 150, 150), anchor="mm")
    im.save(path, "PNG", optimize=True)


def draw_category_image(path, kind, color):
    """imaginea categoriei (portret 141x180 in tema, desenata la 4x)"""
    bg = mix(color, (255, 255, 255), 0.86)
    im = Image.new("RGB", (564, 720), bg)
    d = ImageDraw.Draw(im)
    deseneaza(d, kind, (42, 100, 522, 580), color, bg)
    d.rectangle([0, 660, 564, 720], fill=color)
    im.save(path, "PNG", optimize=True)


def tiles(d, kinds, x, y, size, gap, color):
    """componente desenate pe placi deschise la culoare, una langa alta"""
    for kind in kinds:
        d.rounded_rectangle([x, y, x + size, y + size], radius=size // 8, fill=TILE)
        deseneaza(d, kind, (x + 12, y + 12, x + size - 12, y + size - 12), color, TILE)
        x += size + gap


def draw_slide(path, kinds, color):
    """slide 1110x340 pentru pagina principala; titlul si textul le pune tema, in stanga jos"""
    W, H = 1110, 340
    im = Image.new("RGB", (W, H))
    d = ImageDraw.Draw(im)
    left, right = (20, 24, 30), mix(color, (20, 24, 30), 0.4)
    for x in range(W):
        d.line([(x, 0), (x, H)], fill=mix(left, right, x / W))
    size, gap = 180, 20
    tiles(d, kinds, W - 50 - len(kinds) * size - (len(kinds) - 1) * gap, (H - size) // 2, size, gap, color)
    im.save(path, "PNG", optimize=True)


# numele magazinului din logo (prima parte colorata); acelasi nume e in docker-compose.yml (PS_SHOP_NAME)
MAGAZIN = ("AE", "Hardware")


def draw_logo(path_logo, path_favicon):
    """logo-ul din antet (fundal transparent, la 4x fata de cum se afiseaza) si favicon-ul: un procesor + numele"""
    accent = CATS["Procesoare"][1]
    f = font(True, 64)
    first, rest = MAGAZIN
    w1 = f.getlength(first + " ")
    W, H = int(132 + w1 + f.getlength(rest) + 8), 120
    im = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    deseneaza(d, "cpu", (0, 0, H, H), accent, (255, 255, 255))
    d.text((132, H // 2), first, font=f, fill=accent, anchor="lm")
    d.text((132 + w1, H // 2), rest, font=f, fill=(33, 37, 41), anchor="lm")
    im.save(path_logo, "PNG", optimize=True)

    ico = Image.new("RGBA", (256, 256), (0, 0, 0, 0))
    deseneaza(ImageDraw.Draw(ico), "cpu", (0, 0, 256, 256), accent, (255, 255, 255))
    ico.save(path_favicon, sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])


def draw_banner(path):
    """bannerul 1110x213 de sub produsele noi (ps_banner)"""
    W, H = 1110, 213
    color = CATS["Placi de baza"][1]
    im = Image.new("RGB", (W, H), TILE)
    d = ImageDraw.Draw(im)
    d.rectangle([0, 0, 14, H], fill=color)
    d.text((50, 62), "Construieste-ti PC-ul", font=font(True, 38), fill=(33, 33, 33), anchor="lm")
    d.text((50, 118), "Procesoare, placi video, memorii", font=font(False, 22), fill=(80, 80, 80), anchor="lm")
    d.text((50, 148), "si SSD-uri, intr-un singur loc", font=font(False, 22), fill=(80, 80, 80), anchor="lm")
    size, gap = 165, 16
    tiles_x = W - 24 - 3 * size - 2 * gap
    d.rectangle([tiles_x - 12, 0, W, H], fill=(255, 255, 255))
    tiles(d, ["cpu", "gpu", "ram"], tiles_x, (H - size) // 2, size, gap, color)
    im.save(path, "PNG", optimize=True)

random.seed(2026)
rows = []
counters = {}
for cat, brand, name, price_tin, feats in P:
    code, _, fnames = CATS[cat]
    counters[code] = counters.get(code, 0) + 1
    ref = f"AE-{code}-{counters[code]:03d}"
    fname = f"{ref.lower()}.png"
    cale = cale_categorie(cat, brand, name, feats)
    leaf = cale[-1]
    draw_image(os.path.join(IMG_DIR, fname), cat, brand, name, feats, desen_produs(cat, feats), culoare(cale))
    feat_str = ",".join(f"{fn}:{fv}:{i+1}" for i, (fn, fv) in enumerate(zip(fnames, feats)))
    spec_li = "".join(f"<li><strong>{fn}:</strong> {fv}</li>" for fn, fv in zip(fnames, feats))
    summary = "<p>" + " | ".join(f"{fn}: {fv}" for fn, fv in zip(fnames, feats)) + "</p>"
    desc = (f"<p>{name}, produs din categoria {leaf}, marca {brand}.</p>"
            f"<ul>{spec_li}</ul>"
            f"<p>Preturile si stocurile sunt fictive, folosite doar pentru testarea platformei.</p>")
    qty = random.choice([0, 2, 5, 8, 12, 15, 20, 25, 30, 40, 50])
    price_tex = round(price_tin / 1.21, 6)
    alt = name.replace(",", " ")
    # categoriile de la radacina la subcategorie; produsul apare in toate, implicita e ultima
    rows.append([1, name, ",".join(cale), price_tex, 1, ref, brand, qty, summary, desc,
                 IMG_BASE + fname, alt, feat_str])

header = ["Active (0/1)", "Name", "Categories (x,y,z...)", "Price tax excluded", "Tax rule ID",
          "Reference #", "Brand", "Quantity", "Summary", "Description",
          "Image URLs (x,y,z...)", "Image alt texts (x,y,z...)",
          "Feature (Name:Value:Position:Customized)"]
with open(os.path.join(OUT, "produse.csv"), "w", newline="", encoding="utf-8") as fh:
    w = csv.writer(fh, delimiter=";", quotechar='"', quoting=csv.QUOTE_MINIMAL)
    w.writerow(header)
    w.writerows(rows)

# categoriile, cu descriere si imagine (citite de docker/sync.php, in aceasta ordine)
with open(os.path.join(OUT, "categorii.csv"), "w", newline="", encoding="utf-8") as fh:
    w = csv.writer(fh, delimiter=";", quotechar='"', quoting=csv.QUOTE_MINIMAL)
    w.writerow(["Cale", "Descriere", "Imagine"])
    for i, (cale, descriere, kind) in enumerate(CATEGORII, 1):
        fname = f"cat-{i:02d}.png"
        draw_category_image(os.path.join(IMG_DIR, fname), kind, culoare(cale))
        w.writerow([" > ".join(cale), descriere, fname])

# pagina principala: slide-urile (ps_imageslider) si bannerul (ps_banner), textele sunt in docker/continut.php
draw_slide(os.path.join(IMG_DIR, "ae-slide-1.png"), ["cpu", "gpu", "ram"], CATS["Procesoare"][1])
draw_slide(os.path.join(IMG_DIR, "ae-slide-2.png"), ["laptop", "monitor"], CATS["Monitoare"][1])
draw_slide(os.path.join(IMG_DIR, "ae-slide-3.png"), ["keyboard", "mouse", "headset"], CATS["Periferice"][1])
draw_banner(os.path.join(IMG_DIR, "ae-banner.png"))
draw_logo(os.path.join(IMG_DIR, "ae-logo.png"), os.path.join(IMG_DIR, "ae-favicon.ico"))

print("ok", len(rows), "produse,", len(CATEGORII), "categorii")
