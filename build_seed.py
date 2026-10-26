import csv, random, os, textwrap
from PIL import Image, ImageDraw, ImageFont

# folderul de iesire: implicit langa script, in Docker se trimite SEED_OUT=/seed
OUT = os.environ.get("SEED_OUT") or os.path.dirname(os.path.abspath(__file__))
IMG_DIR = os.path.join(OUT, "seed-img")
os.makedirs(IMG_DIR, exist_ok=True)
IMG_BASE = "http://localhost/seed-img/"

# categorie: (cod, culoare, [nume caracteristici])
CATS = {
    "Procesoare": ("CPU", (198, 40, 40), ["Socket", "Nuclee", "Fire"]),
    "Placi video": ("GPU", (46, 125, 50), ["Chipset", "Memorie video", "Tip memorie"]),
    "Placi de baza": ("MB", (21, 101, 192), ["Socket", "Chipset", "Format"]),
    "Memorii RAM": ("RAM", (106, 27, 154), ["Capacitate", "Tip", "Frecventa"]),
    "SSD": ("SSD", (0, 131, 143), ["Capacitate", "Interfata", "Format"]),
    "Surse": ("PSU", (239, 108, 0), ["Putere", "Certificare", "Modulara"]),
    "Carcase": ("CASE", (69, 90, 100), ["Format", "Panou lateral"]),
    "Monitoare": ("MON", (40, 53, 147), ["Diagonala", "Rezolutie", "Rata refresh", "Panou"]),
    "Laptopuri": ("LAP", (55, 71, 79), ["Procesor", "Memorie RAM", "Stocare", "Diagonala"]),
    "Periferice": ("PER", (173, 20, 87), ["Tip", "Conectivitate"]),
}

# (categorie, brand, nume, pret cu TVA RON (orientativ), [valori caracteristici])
P = [
 ("Procesoare","AMD","AMD Ryzen 5 7600",899,["AM5","6","12"]),
 ("Procesoare","AMD","AMD Ryzen 7 7800X3D",1999,["AM5","8","16"]),
 ("Procesoare","AMD","AMD Ryzen 9 7950X",2599,["AM5","16","32"]),
 ("Procesoare","AMD","AMD Ryzen 5 5600",499,["AM4","6","12"]),
 ("Procesoare","AMD","AMD Ryzen 7 9700X",1599,["AM5","8","16"]),
 ("Procesoare","Intel","Intel Core i5-14600K",1299,["LGA1700","14","20"]),
 ("Procesoare","Intel","Intel Core i7-14700K",1899,["LGA1700","20","28"]),
 ("Procesoare","Intel","Intel Core i9-14900K",2599,["LGA1700","24","32"]),
 ("Procesoare","Intel","Intel Core i5-12400F",549,["LGA1700","6","12"]),
 ("Procesoare","Intel","Intel Core Ultra 7 265K",1899,["LGA1851","20","20"]),

 ("Placi video","Gigabyte","Gigabyte GeForce RTX 4060 Eagle OC 8GB",1499,["NVIDIA GeForce RTX 4060","8 GB","GDDR6"]),
 ("Placi video","ASUS","ASUS Dual GeForce RTX 4060 Ti 8GB",1999,["NVIDIA GeForce RTX 4060 Ti","8 GB","GDDR6"]),
 ("Placi video","MSI","MSI GeForce RTX 5070 Ventus 2X OC 12GB",3199,["NVIDIA GeForce RTX 5070","12 GB","GDDR7"]),
 ("Placi video","Gigabyte","Gigabyte GeForce RTX 4070 Ti Super Gaming OC 16GB",4299,["NVIDIA GeForce RTX 4070 Ti Super","16 GB","GDDR6X"]),
 ("Placi video","ASUS","ASUS TUF Gaming GeForce RTX 5080 16GB",6499,["NVIDIA GeForce RTX 5080","16 GB","GDDR7"]),
 ("Placi video","Sapphire","Sapphire Pulse Radeon RX 7600 8GB",1299,["AMD Radeon RX 7600","8 GB","GDDR6"]),
 ("Placi video","Sapphire","Sapphire Pulse Radeon RX 7700 XT 12GB",2199,["AMD Radeon RX 7700 XT","12 GB","GDDR6"]),
 ("Placi video","PowerColor","PowerColor Hellhound Radeon RX 7800 XT 16GB",2699,["AMD Radeon RX 7800 XT","16 GB","GDDR6"]),
 ("Placi video","XFX","XFX Speedster MERC 310 Radeon RX 7900 XTX 24GB",4999,["AMD Radeon RX 7900 XTX","24 GB","GDDR6"]),
 ("Placi video","ASRock","ASRock Intel Arc B580 Steel Legend 12GB",1499,["Intel Arc B580","12 GB","GDDR6"]),

 ("Placi de baza","MSI","MSI B650 Gaming Plus WiFi",899,["AM5","B650","ATX"]),
 ("Placi de baza","ASUS","ASUS TUF Gaming B650-Plus WiFi",999,["AM5","B650","ATX"]),
 ("Placi de baza","Gigabyte","Gigabyte X670 Aorus Elite AX",1399,["AM5","X670","ATX"]),
 ("Placi de baza","ASRock","ASRock B650M Pro RS",649,["AM5","B650","Micro-ATX"]),
 ("Placi de baza","MSI","MSI MAG B550 Tomahawk",699,["AM4","B550","ATX"]),
 ("Placi de baza","ASUS","ASUS Prime B760M-A WiFi",699,["LGA1700","B760","Micro-ATX"]),
 ("Placi de baza","MSI","MSI PRO Z790-P WiFi",999,["LGA1700","Z790","ATX"]),
 ("Placi de baza","Gigabyte","Gigabyte B760 Gaming X AX",799,["LGA1700","B760","ATX"]),
 ("Placi de baza","ASUS","ASUS ROG Strix Z790-E Gaming WiFi",2399,["LGA1700","Z790","ATX"]),
 ("Placi de baza","MSI","MSI MAG Z890 Tomahawk WiFi",1699,["LGA1851","Z890","ATX"]),

 ("Memorii RAM","Kingston","Kingston FURY Beast 16GB (2x8GB) DDR4 3200MHz",229,["16 GB","DDR4","3200 MHz"]),
 ("Memorii RAM","Corsair","Corsair Vengeance LPX 32GB (2x16GB) DDR4 3600MHz",399,["32 GB","DDR4","3600 MHz"]),
 ("Memorii RAM","Kingston","Kingston FURY Beast 32GB (2x16GB) DDR5 6000MHz",549,["32 GB","DDR5","6000 MHz"]),
 ("Memorii RAM","G.Skill","G.Skill Trident Z5 RGB 32GB (2x16GB) DDR5 6400MHz",699,["32 GB","DDR5","6400 MHz"]),
 ("Memorii RAM","Corsair","Corsair Vengeance RGB 64GB (2x32GB) DDR5 6000MHz",1199,["64 GB","DDR5","6000 MHz"]),
 ("Memorii RAM","Kingston","Kingston FURY Beast 64GB (2x32GB) DDR5 5600MHz",999,["64 GB","DDR5","5600 MHz"]),
 ("Memorii RAM","Crucial","Crucial Pro 32GB (2x16GB) DDR5 5600MHz",479,["32 GB","DDR5","5600 MHz"]),
 ("Memorii RAM","G.Skill","G.Skill Ripjaws V 16GB (2x8GB) DDR4 3600MHz",249,["16 GB","DDR4","3600 MHz"]),
 ("Memorii RAM","TeamGroup","TeamGroup T-Force Delta RGB 32GB (2x16GB) DDR5 6000MHz",599,["32 GB","DDR5","6000 MHz"]),
 ("Memorii RAM","Kingston","Kingston FURY Renegade 32GB (2x16GB) DDR5 6400MHz",749,["32 GB","DDR5","6400 MHz"]),

 ("SSD","Samsung","Samsung 990 PRO 1TB NVMe",649,["1 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Samsung","Samsung 990 PRO 2TB NVMe",1099,["2 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","WD","WD Black SN850X 1TB NVMe",549,["1 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","WD","WD Blue SN580 1TB NVMe",349,["1 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Kingston","Kingston NV2 1TB NVMe",299,["1 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Crucial","Crucial T500 2TB NVMe",799,["2 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Crucial","Crucial P3 Plus 1TB NVMe",319,["1 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Samsung","Samsung 870 EVO 1TB SATA",449,["1 TB","SATA III","2.5 inch"]),
 ("SSD","Kingston","Kingston KC3000 2TB NVMe",849,["2 TB","PCIe 4.0 NVMe","M.2 2280"]),
 ("SSD","Crucial","Crucial T700 2TB NVMe",1499,["2 TB","PCIe 5.0 NVMe","M.2 2280"]),

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

 ("Carcase","NZXT","NZXT H5 Flow",449,["Mid Tower","Sticla securizata"]),
 ("Carcase","Fractal Design","Fractal Design North TG",749,["Mid Tower","Sticla securizata"]),
 ("Carcase","Lian Li","Lian Li O11 Dynamic EVO",799,["Mid Tower","Sticla securizata"]),
 ("Carcase","Corsair","Corsair 4000D Airflow",499,["Mid Tower","Sticla securizata"]),
 ("Carcase","be quiet!","be quiet! Pure Base 500DX",549,["Mid Tower","Sticla securizata"]),
 ("Carcase","Phanteks","Phanteks XT Pro",349,["Mid Tower","Sticla securizata"]),
 ("Carcase","Cooler Master","Cooler Master MasterBox Q300L",229,["Mini Tower","Acrilic"]),
 ("Carcase","Montech","Montech Air 903 Max",449,["Mid Tower","Sticla securizata"]),
 ("Carcase","DeepCool","DeepCool CH560",449,["Mid Tower","Sticla securizata"]),
 ("Carcase","Fractal Design","Fractal Design Pop Air",399,["Mid Tower","Sticla securizata"]),

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

F_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
F_REG = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

def draw_image(path, cat, brand, name, feats):
    code, color, fnames = CATS[cat]
    im = Image.new("RGB", (800, 800), (246, 247, 249))
    d = ImageDraw.Draw(im)
    d.rectangle([0, 0, 800, 120], fill=color)
    d.text((40, 38), cat.upper(), font=ImageFont.truetype(F_BOLD, 40), fill="white")
    # caseta centrala
    d.rounded_rectangle([60, 170, 740, 560], radius=28, fill="white", outline=color, width=4)
    d.text((400, 260), brand, font=ImageFont.truetype(F_BOLD, 64), fill=color, anchor="mm")
    model = name[len(brand):].strip() if name.startswith(brand) else name
    lines = textwrap.wrap(model, 24)[:3]
    fm = ImageFont.truetype(F_BOLD, 38)
    for i, ln in enumerate(lines):
        d.text((400, 350 + i * 52), ln, font=fm, fill=(33, 33, 33), anchor="mm")
    fs = ImageFont.truetype(F_REG, 30)
    for i, (fn, fv) in enumerate(list(zip(fnames, feats))[:3]):
        d.text((80, 600 + i * 44), f"{fn}: {fv}", font=fs, fill=(66, 66, 66))
    d.text((400, 770), "Imagine demonstrativa - proiect Afaceri electronice",
           font=ImageFont.truetype(F_REG, 20), fill=(140, 140, 140), anchor="mm")
    im.save(path, "PNG", optimize=True)

random.seed(2026)
rows = []
counters = {}
for cat, brand, name, price_tin, feats in P:
    code, _, fnames = CATS[cat]
    counters[code] = counters.get(code, 0) + 1
    ref = f"AE-{code}-{counters[code]:03d}"
    fname = f"{ref.lower()}.png"
    draw_image(os.path.join(IMG_DIR, fname), cat, brand, name, feats)
    feat_str = ",".join(f"{fn}:{fv}:{i+1}" for i, (fn, fv) in enumerate(zip(fnames, feats)))
    spec_li = "".join(f"<li><strong>{fn}:</strong> {fv}</li>" for fn, fv in zip(fnames, feats))
    summary = "<p>" + " | ".join(f"{fn}: {fv}" for fn, fv in zip(fnames, feats)) + "</p>"
    desc = (f"<p>{name}, produs din categoria {cat.lower()}, marca {brand}.</p>"
            f"<ul>{spec_li}</ul>"
            f"<p>Preturile si stocurile sunt fictive, folosite doar pentru testarea platformei.</p>")
    qty = random.choice([0, 2, 5, 8, 12, 15, 20, 25, 30, 40, 50])
    price_tex = round(price_tin / 1.21, 6)
    alt = name.replace(",", " ")
    rows.append([1, name, cat, price_tex, 1, ref, brand, qty, summary, desc,
                 IMG_BASE + fname, alt, feat_str])

header = ["Active (0/1)", "Name", "Categories (x,y,z...)", "Price tax excluded", "Tax rule ID",
          "Reference #", "Brand", "Quantity", "Summary", "Description",
          "Image URLs (x,y,z...)", "Image alt texts (x,y,z...)",
          "Feature (Name:Value:Position:Customized)"]
with open(os.path.join(OUT, "produse.csv"), "w", newline="", encoding="utf-8") as fh:
    w = csv.writer(fh, delimiter=";", quotechar='"', quoting=csv.QUOTE_MINIMAL)
    w.writerow(header)
    w.writerows(rows)
print("ok", len(rows))
