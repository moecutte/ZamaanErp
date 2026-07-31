<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasRoleAccess;
use App\Services\ReportExportService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Response;

class Reports extends Page implements HasForms
{
    use HasRoleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Reports';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title = 'Export Reports';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.reports';
    protected static ?string $slug = 'reports';

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'report' => 'sales_by_channel',
            'format' => 'csv',
            'from'   => now()->subDays(30)->toDateString(),
            'to'     => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('report')
                    ->options([
                        'sales_by_channel'         => 'Sales by Channel',
                        'top_products'             => 'Top Products',
                        'stock_aging'              => 'Stock Aging',
                        'wastage'                  => 'Wastage %',
                        'revenue_by_customer_type' => 'Revenue by Customer Type',
                    ])
                    ->required(),

                Select::make('format')
                    ->options([
                        'csv' => 'CSV',
                        'pdf' => 'PDF',
                    ])
                    ->required(),

                DatePicker::make('from')->required(),
                DatePicker::make('to')->required(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function export(): StreamedResponse|Response|null
    {
        $data = $this->form->getState();

        try {
            return app(ReportExportService::class)->export(
                report: $data['report'],
                format: $data['format'],
                from: \Illuminate\Support\Carbon::parse($data['from']),
                to: \Illuminate\Support\Carbon::parse($data['to']),
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Export failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }
}
