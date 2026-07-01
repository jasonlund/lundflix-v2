<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Download\Settings\DownloadSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'App Settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(DownloadSettings $settings): void
    {
        $this->form->fill([
            'uid' => $settings->uid,
            'pass' => $settings->pass,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Downloads')->schema([
                    TextInput::make('uid')->required(),
                    TextInput::make('pass')->required(),
                ]),
            ])
            ->statePath('data');
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(DownloadSettings $settings): void
    {
        $data = $this->form->getState();

        $settings->uid = $data['uid'];
        $settings->pass = $data['pass'];
        $settings->save();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
