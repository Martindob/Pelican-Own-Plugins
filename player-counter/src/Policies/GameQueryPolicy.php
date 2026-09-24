<?php

namespace Boy132\PlayerCounter\Policies;

use App\Policies\DefaultAdminPolicies;

class GameQueryPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'game_query';
}
