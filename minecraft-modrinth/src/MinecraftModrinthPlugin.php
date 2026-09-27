<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use Closure;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Crypt;

class MinecraftModrinthPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecraft-modrinth';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Boy132\\MinecraftModrinth\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        // Flat and explicit on purpose: this array is sent to the browser to fill the form, so
        // the (encrypted) GitHub token must never be part of it.
        return [
            'always_use_latest_version' => (bool) config('minecraft-modrinth.always_use_latest_version'),
            'auto_update_enabled' => (bool) config('minecraft-modrinth.auto_update_enabled'),
            'auto_update_time' => config('minecraft-modrinth.auto_update_time'),
            'github_enabled' => (bool) config('minecraft-modrinth.github.enabled'),
            'github_repository' => (string) config('minecraft-modrinth.github.repository'),
            'github_branch' => (string) config('minecraft-modrinth.github.branch', 'main'),
            'github_index_path' => (string) config('minecraft-modrinth.github.index_path', 'minecraft/releases.json'),
            'github_token' => null,
            'github_token_clear' => false,
            'github_require_green_ci' => (bool) config('minecraft-modrinth.github.require_green_ci', true),
        ];
    }

    public function getSettingsForm(): array
    {
        $hasToken = app(GitHubPluginService::class)->hasToken();

        return [
            Toggle::make('always_use_latest_version')
                ->label(trans('minecraft-modrinth::strings.settings.always_use_latest_version'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.always_use_latest_version_hint'))
                ->inline(false)
                ->default(fn () => config('minecraft-modrinth.always_use_latest_version')),
            Toggle::make('auto_update_enabled')
                ->label(trans('minecraft-modrinth::strings.settings.auto_update_enabled'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.auto_update_enabled_hint'))
                ->inline(false)
                ->default(fn () => config('minecraft-modrinth.auto_update_enabled')),
            TimePicker::make('auto_update_time')
                ->label(trans('minecraft-modrinth::strings.settings.auto_update_time'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.auto_update_time_hint', ['timezone' => user()->timezone ?? config('app.timezone')]))
                ->native(false)
                ->seconds(false)
                ->required()
                ->default(fn () => config('minecraft-modrinth.auto_update_time')),
            Section::make(trans('minecraft-modrinth::strings.github.settings.section'))
                ->description(trans('minecraft-modrinth::strings.github.settings.section_description'))
                ->collapsible()
                ->schema([
                    Toggle::make('github_enabled')
                        ->label(trans('minecraft-modrinth::strings.github.settings.enabled'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.enabled_hint'))
                        ->inline(false)
                        ->live()
                        ->default(fn () => config('minecraft-modrinth.github.enabled')),
                    TextInput::make('github_repository')
                        ->label(trans('minecraft-modrinth::strings.github.settings.repository'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.repository_hint'))
                        ->placeholder('owner/repository')
                        ->maxLength(140)
                        ->requiredIfAccepted('github_enabled')
                        ->rule(fn () => $this->validationRule(fn (string $value) => GitHubSourceRules::isValidRepository($value), 'repository_invalid')),
                    TextInput::make('github_branch')
                        ->label(trans('minecraft-modrinth::strings.github.settings.branch'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.branch_hint'))
                        ->placeholder('main')
                        ->maxLength(100)
                        ->rule(fn () => $this->validationRule(fn (string $value) => GitHubSourceRules::isValidBranch($value), 'branch_invalid')),
                    TextInput::make('github_index_path')
                        ->label(trans('minecraft-modrinth::strings.github.settings.index_path'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.index_path_hint'))
                        ->placeholder('minecraft/releases.json')
                        ->maxLength(512)
                        ->rule(fn () => $this->validationRule(fn (string $value) => GitHubSourceRules::isValidIndexPath($value), 'index_path_invalid')),
                    TextInput::make('github_token')
                        ->label(trans('minecraft-modrinth::strings.github.settings.token'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.token_hint'))
                        ->password()
                        ->revealable(false)
                        ->autocomplete('new-password')
                        ->placeholder($hasToken
                            ? trans('minecraft-modrinth::strings.github.settings.token_saved')
                            : trans('minecraft-modrinth::strings.github.settings.token_empty'))
                        ->maxLength(255)
                        ->rule(fn () => $this->validationRule(fn (string $value) => GitHubSourceRules::isPlausibleToken(trim($value)), 'token_invalid')),
                    Toggle::make('github_token_clear')
                        ->label(trans('minecraft-modrinth::strings.github.settings.token_clear'))
                        ->inline(false)
                        ->visible($hasToken)
                        ->default(false),
                    Toggle::make('github_require_green_ci')
                        ->label(trans('minecraft-modrinth::strings.github.settings.require_green_ci'))
                        ->helperText(trans('minecraft-modrinth::strings.github.settings.require_green_ci_hint'))
                        ->inline(false)
                        ->default(fn () => config('minecraft-modrinth.github.require_green_ci', true)),
                    Actions::make([
                        Action::make('github_test_connection')
                            ->label(trans('minecraft-modrinth::strings.github.settings.test_connection'))
                            ->tooltip(trans('minecraft-modrinth::strings.github.settings.test_connection_hint'))
                            ->icon('tabler-plug-connected')
                            ->color('gray')
                            ->action(fn () => $this->testGitHubConnection()),
                    ]),
                ]),
        ];
    }

    /**
     * A validation rule that accepts empty values and otherwise defers to $check.
     *
     * @param  Closure(string): bool  $check
     */
    protected function validationRule(Closure $check, string $messageKey): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($check, $messageKey) {
            if (is_string($value) && $value !== '' && !$check($value)) {
                $fail(trans('minecraft-modrinth::strings.github.settings.'.$messageKey));
            }
        };
    }

    public function saveSettings(array $data): void
    {
        $values = [
            'MINECRAFT_MODRINTH_ALWAYS_USE_LATEST_VERSION' => $data['always_use_latest_version'],
            'MINECRAFT_MODRINTH_AUTO_UPDATE_ENABLED' => $data['auto_update_enabled'],
            'MINECRAFT_MODRINTH_AUTO_UPDATE_TIME' => $data['auto_update_time'],
            // The time above is only meaningful together with a timezone: capture the saving
            // admin's own account timezone here so the schedule actually fires at that wall-clock
            // time, instead of Schedule::dailyAt() defaulting to config('app.timezone') (UTC on a
            // typical Pelican install), which would silently shift it for anyone elsewhere.
            'MINECRAFT_MODRINTH_AUTO_UPDATE_TIMEZONE' => user()->timezone ?? config('app.timezone'),
        ];

        if (array_key_exists('github_enabled', $data)) {
            $github = $this->githubEnvironmentValues($data);

            if ($github === null) {
                return;
            }

            $values += $github;
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title(trans('minecraft-modrinth::strings.settings.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * .env values of the GitHub source, or null (after telling the admin) when they're invalid.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function githubEnvironmentValues(array $data): ?array
    {
        $enabled = (bool) $data['github_enabled'];
        $repository = trim((string) ($data['github_repository'] ?? ''));
        $branch = trim((string) ($data['github_branch'] ?? '')) ?: 'main';
        $indexPath = trim((string) ($data['github_index_path'] ?? '')) ?: 'minecraft/releases.json';
        $token = trim((string) ($data['github_token'] ?? ''));

        // Re-checked here as well: the form rules are the first line, not the only one.
        $valid = ($repository === '' ? !$enabled : GitHubSourceRules::isValidRepository($repository))
            && GitHubSourceRules::isValidBranch($branch)
            && GitHubSourceRules::isValidIndexPath($indexPath)
            && ($token === '' || GitHubSourceRules::isPlausibleToken($token));

        if (!$valid) {
            Notification::make()
                ->title(trans('minecraft-modrinth::strings.github.settings.invalid_settings'))
                ->danger()
                ->send();

            return null;
        }

        $values = [
            'MINECRAFT_MODRINTH_GITHUB_ENABLED' => $enabled,
            'MINECRAFT_MODRINTH_GITHUB_REPOSITORY' => $repository,
            'MINECRAFT_MODRINTH_GITHUB_BRANCH' => $branch,
            'MINECRAFT_MODRINTH_GITHUB_INDEX_PATH' => $indexPath,
            'MINECRAFT_MODRINTH_GITHUB_REQUIRE_GREEN_CI' => (bool) ($data['github_require_green_ci'] ?? true),
        ];

        if (!empty($data['github_token_clear'])) {
            $values['MINECRAFT_MODRINTH_GITHUB_TOKEN_ENCRYPTED'] = '';
        } elseif ($token !== '') {
            // Only the ciphertext (encrypted with APP_KEY) ever reaches the .env file. An empty
            // field keeps whatever is saved already.
            $values['MINECRAFT_MODRINTH_GITHUB_TOKEN_ENCRYPTED'] = Crypt::encryptString($token);
        }

        return $values;
    }

    /** Uses the saved settings (save first), so it tests exactly what servers will use. */
    protected function testGitHubConnection(): void
    {
        $service = app(GitHubPluginService::class);

        try {
            $service->forgetCachedState();
            $snapshot = $service->getSnapshot();

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.github.settings.test_ok'))
                ->body(trans('minecraft-modrinth::strings.github.settings.test_ok_body', [
                    'commit' => substr($snapshot['sha'], 0, 7),
                    'ci' => $snapshot['ci']->getLabel(),
                    'count' => count($snapshot['plugins']),
                    'problems' => count($snapshot['problems']),
                ]))
                ->success()
                ->send();
        } catch (GitHubSourceException $exception) {
            Notification::make()
                ->title(trans('minecraft-modrinth::strings.github.settings.test_failed'))
                ->body($exception->getUserMessage())
                ->danger()
                ->send();
        }
    }
}
