<?php

return [
    'enabled' => 'Zapnuto',
    'enabled_helper' => 'Automaticky připravuje a instaluje nejnovější build Paperu/Velocity. Kontroluje se každou hodinu na pozadí, nasadí se při dalším startu/restartu serveru.',
    'cache_minutes' => 'Mezipaměť verzí a buildů (minuty)',
    'cache_minutes_helper' => 'Jak dlouho si plugin pamatuje zjištěnou nejnovější verzi a build, než se PaperMC zeptá znovu.',
    'download_timeout' => 'Časový limit stahování (sekundy)',
    'download_timeout_helper' => 'Jak dlouho hodinová kontrola na pozadí čeká, než daemon stáhne a připraví nový jar. Zvyš ho, pokud mají nody pomalé spojení s CDN PaperMC. Záměrně je mnohem vyšší než výchozích 15 sekund daemonu - jar o velikosti ~50-60 MB jich reálně potřebuje víc. Nikdy neovlivní samotný start/restart serveru, ten jen vymění už připravený soubor.',
    'report_throttle' => 'Omezení zápisu chyb do logu (minuty)',
    'report_throttle_helper' => 'Stejná chyba u jednoho serveru se do logu panelu zapíše nejvýš jednou za tolik minut, bez ohledu na to, kolikrát ji hodinová kontrola mezitím zopakuje. 0 = zapsat každý výskyt.',
    'check_now' => 'Zkontrolovat teď',
    'check_complete' => 'Kontrola dokončena',
    'check_complete_body' => 'Výsledek uvidíš níže, až toto nastavení zavřeš a znovu otevřeš.',
    'last_check' => 'Poslední kontrola',
    'last_check_never' => 'Zatím neproběhla. Použij „Zkontrolovat teď“ a pak toto okno znovu otevři - pokud tu bude i potom tato zpráva, hodinový plánovač nejspíš neběží (zkontroluj, že je na hostiteli panelu v cronu `php artisan schedule:run`).',
    'last_check_stale' => ' - to je víc než před 2 hodinami, což obvykle znamená, že hodinová kontrola přestala běžet (zkontroluj, že je na hostiteli panelu v cronu `php artisan schedule:run`).',
    'recent_activity' => 'Nedávná aktivita',
    'no_activity' => 'Zatím žádná zaznamenaná aktivita.',
    'saved' => 'Nastavení uloženo',
];
