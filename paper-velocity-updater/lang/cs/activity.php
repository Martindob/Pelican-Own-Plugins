<?php

return [
    'staged' => 'Připraven :project build :build (:version) pro server #:server (:name) - nasadí se při jeho dalším startu/restartu.',
    'updated' => 'Server #:server (:name) aktualizován na :project build :build (:version).',
    'up_to_date' => 'Server #:server (:name): už má aktuální :project build :build (:version) - není co připravit.',
    'check_failed' => 'Kontrola serveru #:server (:name) selhala: :error',
    'swap_failed' => 'Výměna jaru na serveru #:server (:name) selhala: :error',
    'no_downgrade_installed' => 'Server #:server (:name): cílem je :project :version build :build, ale nainstalovaný je už :installed_version build :installed_build - na starší verzi se automaticky nepřechází. Buď nastav proměnnou verze na :installed_version, nebo obnov zálohu světa spolu se souborem :jar.old (jar z doby před poslední aktualizací) a smaž :state_file.',
    'no_downgrade_running' => 'Server #:server (:name): cílem je paper :version, ale server naposledy běžel na Minecraftu :running - na starší verzi se automaticky nepřechází. Buď nastav proměnnou verze na :running, nebo obnov zálohu světa z verze :version spolu s jarem pro :version.',
    'staged_mismatch' => 'Server #:server (:name): připravený :project build :build (:version) se nenasadí - už neodpovídá nastavení BUILD_NUMBER/verze tohoto serveru.',
    'staged_incomplete' => 'Server #:server (:name): připravený soubor :file má :size bajtů místo :expected - nenasadí se a stáhne se znovu.',
    'build_pinned' => 'Server #:server (:name): BUILD_NUMBER je nastavené na „:value“, ne „latest“ - pokud se má server aktualizovat automaticky, nech ho na „latest“.',
    'dl_path' => 'Server #:server (:name): je nastavené DL_PATH - server je z aktualizací přes PaperMC vyřazený.',
    'invalid_jarfile' => 'Server #:server (:name): SERVER_JARFILE („:value“) není obyčejný název .jar souboru - plugin na něj nesáhne.',
    'latest_unresolved' => 'Server #:server (:name): nejnovější stabilní build teď nejde zjistit (PaperMC je nedostupné, nebo žádná z :count nejnovějších verzí nemá stabilní build) - zkusí se to znovu při další kontrole.',
    'pinned_unknown' => 'Server #:server (:name): zamčená verze „:version“ neodpovídá žádné verzi, kterou PaperMC uvádí pro :project - zkontroluj překlep.',
    'pinned_no_build' => 'Server #:server (:name): pro zamčenou verzi „:version“ se nenašel žádný build.',
    'no_download' => 'Server #:server (:name): build :build od PaperMC pro :project :version nemá soubor ke stažení.',
    'bad_download_url' => 'Server #:server (:name): odkaz ke stažení neprošel kontrolou (host „:host“, schéma „:scheme“) - z bezpečnostních důvodů se odmítá.',
];
