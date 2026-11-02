<?php
/**
 * Regulile de compatibilitate dintre componentele unui PC, pe baza caracteristicilor produselor
 * (generate de build_seed.py). Folosite de pagina "Creaza propriul tau PC" si de serverul MCP.
 *
 *  - procesor + placa de baza: acelasi Socket
 *  - procesor + memorie: Tip (memorie) se afla in "Memorie suportata" (procesor)
 *  - placa de baza + memorie: "Tip memorie" (placa) = Tip (memorie)
 *  - placa de baza + carcasa: Format (placa) se afla in "Placi de baza suportate" (carcasa)
 *  - sursa: Putere >= sursa recomandata = (suma "Consum" a tuturor componentelor alese + alte consumuri) + 30%,
 *    deci si invers: cu sursa aleasa, la celelalte componente raman doar cele care mai incap in puterea ei
 *
 * Daca unui produs ii lipseste o caracteristica, regula respectiva nu se aplica.
 */
class AeCompatibility
{
    /** componentele unui PC: cheie => [eticheta, categoria (link_rewrite)], in ordinea din configurator */
    const SLOTS = [
        'procesor' => ['Procesor', 'procesoare'],
        'placa_de_baza' => ['Placa de baza', 'placi-de-baza'],
        'memorie' => ['Memorie RAM', 'memorii-ram'],
        'placa_video' => ['Placa video', 'placi-video'],
        'ssd' => ['SSD', 'ssd'],
        'sursa' => ['Sursa', 'surse'],
        'carcasa' => ['Carcasa', 'carcase'],
    ];

    /** ce nu se alege in configurator, dar consuma: cooler-ul procesorului, dispozitive USB (W) */
    const OTHER_POWER = 25;

    const OTHER_POWER_LABEL = 'Altele (cooler procesor, dispozitive USB)';

    /** rezerva de putere pentru sursa (30%) */
    const PSU_MARGIN = 1.3;

    /** @var AeCatalog */
    private $catalog;

    /** @var array slot => [id_product => produs] */
    private $slotProducts = [];

    public function __construct(AeCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /** produsele unei componente (ex. toate procesoarele), dupa id */
    public function slotProducts($slot)
    {
        if (!isset($this->slotProducts[$slot])) {
            $this->slotProducts[$slot] = [];
            foreach ($this->catalog->products(['categorie' => self::SLOTS[$slot][1]]) as $product) {
                $this->slotProducts[$slot][$product['id']] = $product;
            }
        }

        return $this->slotProducts[$slot];
    }

    /** componenta din care face parte produsul (ex. "procesor") sau null */
    public function slotOf($idProduct)
    {
        foreach (array_keys(self::SLOTS) as $slot) {
            if (isset($this->slotProducts($slot)[(int) $idProduct])) {
                return $slot;
            }
        }

        return null;
    }

    /** [slot => id_product] => [slot => produs]; ignora sloturile necunoscute si produsele care nu sunt in slot */
    public function resolve(array $ids)
    {
        $selection = [];
        foreach ($ids as $slot => $idProduct) {
            if (isset(self::SLOTS[$slot]) && isset($this->slotProducts($slot)[(int) $idProduct])) {
                $selection[$slot] = $this->slotProducts($slot)[(int) $idProduct];
            }
        }

        return $selection;
    }

    /**
     * Produsele compatibile pentru $slot cu celelalte componente alese (ordonate dupa pret).
     *
     * @return array compatibile, incompatibile (cate au fost ascunse), conditii (texte pentru utilizator)
     */
    public function candidates($slot, array $selection)
    {
        unset($selection[$slot]);
        $compatible = [];
        $hidden = 0;
        foreach ($this->slotProducts($slot) as $product) {
            if ($this->problems($selection + [$slot => $product], $slot)) {
                ++$hidden;
            } else {
                $compatible[] = $product;
            }
        }
        usort($compatible, function ($a, $b) {
            return $a['pret'] <=> $b['pret'];
        });

        return ['compatibile' => $compatible, 'incompatibile' => $hidden, 'conditii' => $this->conditions($slot, $selection)];
    }

    /**
     * Problemele de compatibilitate din configuratie (texte). Cu $slot, doar cele care il privesc.
     */
    public function problems(array $selection, $slot = null)
    {
        $problems = [];
        foreach ($this->rules() as list($a, $b, $check)) {
            if (isset($selection[$a], $selection[$b]) && ($slot === null || $slot === $a || $slot === $b)) {
                if ($problem = $check($selection[$a], $selection[$b])) {
                    $problems[] = $problem;
                }
            }
        }
        // sursa depinde de consumul tuturor componentelor, deci priveste orice componenta
        if (isset($selection['sursa'])) {
            $needed = $this->recommendedPsu($selection);
            $power = self::number($selection['sursa'], 'Putere');
            if ($needed && $power && $power < $needed) {
                $problems[] = sprintf('sursa are %d W, dar configuratia are nevoie de minim %d W', $power, $needed);
            }
        }

        return $problems;
    }

    /**
     * Consumul pe componente: [[eticheta, produs, W], ...] + randul "Altele";
     * gol daca nu e aleasa nicio componenta care consuma (sursa nu se pune).
     */
    public function powerBreakdown(array $selection)
    {
        $rows = [];
        foreach (self::SLOTS as $slot => list($label)) {
            if ($slot !== 'sursa' && isset($selection[$slot])) {
                $rows[] = ['eticheta' => $label, 'produs' => $selection[$slot]['nume'], 'w' => self::number($selection[$slot], 'Consum')];
            }
        }
        if ($rows) {
            $rows[] = ['eticheta' => self::OTHER_POWER_LABEL, 'produs' => null, 'w' => self::OTHER_POWER];
        }

        return $rows;
    }

    /** consumul estimat al configuratiei, in W: suma consumului componentelor alese + "Altele" */
    public function estimatedPower(array $selection)
    {
        return array_sum(array_column($this->powerBreakdown($selection), 'w'));
    }

    /** puterea minima a sursei: consumul estimat + 30%, rotunjit in sus la 50 W */
    public function recommendedPsu(array $selection)
    {
        $power = $this->estimatedPower($selection);

        return $power ? (int) (ceil($power * self::PSU_MARGIN / 50) * 50) : 0;
    }

    /** rezumatul configuratiei: componente, total, consum, sursa recomandata, probleme */
    public function summary(array $selection)
    {
        $total = 0;
        $items = [];
        foreach (self::SLOTS as $slot => list($label)) {
            if (isset($selection[$slot])) {
                $total += $selection[$slot]['pret'];
                $items[] = ['componenta' => $slot, 'eticheta' => $label, 'produs' => $selection[$slot]];
            }
        }

        return [
            'componente' => $items,
            'lipsa' => array_values(array_diff_key(array_map(function ($s) {
                return $s[0];
            }, self::SLOTS), $selection)),
            'total' => $total,
            'total_text' => $this->catalog->formatPrice($total),
            'consum_componente' => $this->powerBreakdown($selection),
            'consum_estimat' => $this->estimatedPower($selection),
            'sursa_recomandata' => $this->recommendedPsu($selection),
            'probleme' => $this->problems($selection),
        ];
    }

    /** starea completa pentru pagina "Creaza propriul tau PC" */
    public function builderState(array $ids)
    {
        $selection = $this->resolve($ids);
        $slots = [];
        foreach (self::SLOTS as $slot => list($label, $category)) {
            $slots[] = [
                'cheie' => $slot,
                'eticheta' => $label,
                'imagine' => $this->catalog->categoryImage($category),
                'ales' => $selection[$slot] ?? null,
            ] + $this->candidates($slot, $selection);
        }

        return ['componente' => $slots, 'rezumat' => $this->summary($selection)];
    }

    /** perechile de componente verificate: [slot A, slot B, functie(A, B) => problema sau null] */
    private function rules()
    {
        return [
            ['procesor', 'placa_de_baza', function ($cpu, $board) {
                $a = self::feature($cpu, 'Socket');
                $b = self::feature($board, 'Socket');

                return $a && $b && $a !== $b ? "procesorul are socket $a, iar placa de baza are socket $b" : null;
            }],
            ['procesor', 'memorie', function ($cpu, $ram) {
                $supported = self::values($cpu, 'Memorie suportata');
                $type = self::feature($ram, 'Tip');

                return $supported && $type && !in_array($type, $supported, true)
                    ? 'procesorul suporta ' . implode(' / ', $supported) . ", iar memoria este $type" : null;
            }],
            ['placa_de_baza', 'memorie', function ($board, $ram) {
                $a = self::feature($board, 'Tip memorie');
                $b = self::feature($ram, 'Tip');

                return $a && $b && $a !== $b ? "placa de baza foloseste $a, iar memoria este $b" : null;
            }],
            ['placa_de_baza', 'carcasa', function ($board, $case) {
                $format = self::feature($board, 'Format');
                $supported = self::values($case, 'Placi de baza suportate');

                return $format && $supported && !in_array($format, $supported, true)
                    ? "placa de baza are format $format, iar carcasa suporta doar " . implode(' / ', $supported) : null;
            }],
        ];
    }

    /** ce conditii trebuie sa indeplineasca produsele din $slot, fata de componentele alese */
    private function conditions($slot, array $selection)
    {
        $conditions = [];
        $cpu = $selection['procesor'] ?? null;
        $board = $selection['placa_de_baza'] ?? null;
        $ram = $selection['memorie'] ?? null;
        $case = $selection['carcasa'] ?? null;
        $psu = $selection['sursa'] ?? null;

        switch ($slot) {
            case 'procesor':
                if ($board) {
                    $conditions[] = 'socket ' . self::feature($board, 'Socket') . ' (ca placa de baza)';
                }
                if ($ram) {
                    $conditions[] = 'suporta memorie ' . self::feature($ram, 'Tip');
                }
                break;
            case 'placa_de_baza':
                if ($cpu) {
                    $conditions[] = 'socket ' . self::feature($cpu, 'Socket') . ' (ca procesorul)';
                }
                if ($ram) {
                    $conditions[] = 'memorie ' . self::feature($ram, 'Tip');
                } elseif ($cpu) {
                    $conditions[] = 'memorie ' . self::feature($cpu, 'Memorie suportata');
                }
                if ($case) {
                    $conditions[] = 'format ' . self::feature($case, 'Placi de baza suportate') . ' (incape in carcasa)';
                }
                break;
            case 'memorie':
                if ($board) {
                    $conditions[] = 'tip ' . self::feature($board, 'Tip memorie') . ' (ca placa de baza)';
                } elseif ($cpu) {
                    $conditions[] = 'tip ' . self::feature($cpu, 'Memorie suportata') . ' (suportat de procesor)';
                }
                break;
            case 'carcasa':
                if ($board) {
                    $conditions[] = 'suporta placi ' . self::feature($board, 'Format');
                }
                break;
            case 'sursa':
                if ($needed = $this->recommendedPsu($selection)) {
                    $conditions[] = sprintf('putere de minim %d W (consumul componentelor alese, %d W, + 30%% rezerva)',
                        $needed, $this->estimatedPower($selection));
                }
                break;
        }
        if ($psu && $slot !== 'sursa') {
            // cat mai poate consuma componenta: puterea sursei fara rezerva, minus restul configuratiei
            $power = self::number($psu, 'Putere');
            $others = $selection;
            unset($others['sursa']);
            $used = $others ? $this->estimatedPower($others) : self::OTHER_POWER;
            $conditions[] = sprintf('consum de maxim %d W (cat mai permite sursa de %d W)', max(0, floor($power / self::PSU_MARGIN) - $used), $power);
        }

        return $conditions;
    }

    private static function feature(array $product, $name)
    {
        return $product['caracteristici'][$name] ?? null;
    }

    /** valori multiple: "DDR4 / DDR5" => ["DDR4", "DDR5"] */
    private static function values(array $product, $name)
    {
        $value = self::feature($product, $name);

        return $value === null ? [] : array_map('trim', explode('/', $value));
    }

    /** "750 W" => 750 */
    private static function number(array $product, $name)
    {
        return (int) self::feature($product, $name);
    }
}
