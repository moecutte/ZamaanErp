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
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page implements HasForms
{
    use HasRoleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Reports';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title = 'Reports';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.reports';
    protected static ?string $slug = 'reports';

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public string $category = ReportExportService::CATEGORY_SALES;

    public ?array $data = [];

    /** @var array{title: string, headers: list<string>, rows: list<list<mixed>>}|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $export = app(ReportExportService::class);

        $this->form->fill([
            'report' => $export->defaultReportForCategory($this->category),
            'format' => 'csv',
            'from'   => now()->subDays(30)->toDateString(),
            'to'     => now()->toDateString(),
        ]);

        $this->loadPreview();
    }

    public function setCategory(string $category): void
    {
        $export = app(ReportExportService::class);
        $allowed = array_keys($export->reportsForCategory($category));

        if ($allowed === []) {
            return;
        }

        $this->category = $category;
        $this->data['report'] = $export->defaultReportForCategory($category);
        $this->form->fill([
            'report' => $this->data['report'],
            'format' => $this->data['format'] ?? 'csv',
            'from' => $this->data['from'] ?? now()->subDays(30)->toDateString(),
            'to' => $this->data['to'] ?? now()->toDateString(),
        ]);

        $this->loadPreview();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('report')
                    ->label('Report')
                    ->options(fn () => app(ReportExportService::class)->reportsForCategory($this->category))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadPreview()),

                Select::make('format')
                    ->options([
                        'csv' => 'CSV',
                        'pdf' => 'PDF',
                    ])
                    ->required(),

                DatePicker::make('from')
                    ->native(true)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadPreview()),

                DatePicker::make('to')
                    ->native(true)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadPreview()),
            ])
            ->columns(2)
            ->statePath('data');
    }

    /**
     * @return array<string, array{label: string, reports: array<string, string>}>
     */
    public function getCategoryTreeProperty(): array
    {
        $export = app(ReportExportService::class);

        return [
            ReportExportService::CATEGORY_SALES => [
                'label' => 'Sales report',
                'reports' => $export->reportsForCategory(ReportExportService::CATEGORY_SALES),
            ],
            ReportExportService::CATEGORY_PAYMENTS => [
                'label' => 'Payment & debt report',
                'reports' => $export->reportsForCategory(ReportExportService::CATEGORY_PAYMENTS),
            ],
            ReportExportService::CATEGORY_WASTAGE => [
                'label' => 'Wastage report',
                'reports' => $export->reportsForCategory(ReportExportService::CATEGORY_WASTAGE),
            ],
        ];
    }

    public function selectSubReport(string $report): void
    {
        $allowed = array_keys(app(ReportExportService::class)->reportsForCategory($this->category));

        if (! in_array($report, $allowed, true)) {
            return;
        }

        $this->data['report'] = $report;
        $this->form->fill($this->data);
        $this->loadPreview();
    }

    public function loadPreview(): void
    {
        try {
            $data = $this->form->getRawState();

            if (empty($data['report']) || empty($data['from']) || empty($data['to'])) {
                $this->preview = null;

                return;
            }

            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            $this->preview = app(ReportExportService::class)->build(
                report: $data['report'],
                from: $from,
                to: $to,
            );
        } catch (\Throwable $e) {
            $this->preview = null;

            Notification::make()
                ->title('Could not load preview')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function export(): StreamedResponse|Response|null
    {
        $data = $this->form->getState();

        try {
            $this->loadPreview();

            return app(ReportExportService::class)->export(
                report: $data['report'],
                format: $data['format'],
                from: Carbon::parse($data['from']),
                to: Carbon::parse($data['to']),
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
