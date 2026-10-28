<?php

namespace Tests\Feature;

use App\Filament\Resources\Clientis\Pages\EditClienti;
use App\Models\Clienti;
use App\Models\Pratica;
use App\Models\Proforma;
use App\Models\Provvigione;
use App\Models\User;
use App\Services\ProformaBanca\ElencoProformaBanca;
use App\Services\ProformaBanca\EsitoImportProformaBanca;
use App\Services\ProformaBanca\ImportaProformaBanca;
use App\Services\ProformaBanca\LettoreProformaBanca;
use App\Services\ProformaBanca\RigaLettaDallAi;
use App\Services\ProformaBanca\RigaProformaBanca;
use Database\Seeders\ProformaBancaDocumentTypeSeeder;
use Database\Seeders\ProvvigioniStatoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Validator;
use RuntimeException;
use Tests\TestCase;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Filament\RelationManagers\DocumentsRelationManager;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;

class ImportaProformaBancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ProvvigioniStatoSeeder::class, ProformaBancaDocumentTypeSeeder::class]);
        DB::table('clientis')->insert(['name' => 'Banca Alfa', 'piva' => '01234567890']);
    }

    private function pratica(string $codice, string $cf, string $cognome, string $importo, string $nome = ''): void
    {
        Pratica::create(['codice_pratica' => $codice, 'codice_fiscale' => $cf, 'cognome_cliente' => $cognome, 'nome_cliente' => $nome, 'denominazione_banca' => 'Banca Alfa', 'upload_at' => now()]);
        Provvigione::unguarded(fn () => Provvigione::query()->create([
            'id' => $codice, 'id_pratica' => $codice, 'importo' => $importo,
            'entrata_uscita' => 'Entrata', 'stato' => 'Inserito', 'istituto_finanziario' => 'Banca Alfa',
        ]));
    }

    private function importa(string $csv): EsitoImportProformaBanca
    {
        return app(ImportaProformaBanca::class)->importa($csv, 'banca.csv', 'text/csv', '2026-10-28');
    }

    public function test_matching_amounts_store_the_document_update_the_bank_code_and_create_the_proforma(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '150.00');
        $this->pratica('QT2', 'BNCLRA85M41F205Z', 'Bianchi', '1234.50');

        $esito = $this->importa("Numero pratica;Cognome;Codice fiscale;Provvigione\nBK-77;Rossi;RSSMRA80A01H501U;150,00\nBK-88;Bianchi;BNCLRA85M41F205Z;1.234,50\n");

        $this->assertTrue($esito->ok());
        $this->assertSame('Banca Alfa', $esito->banca);
        $this->assertSame(2, $esito->abbinate);
        $this->assertSame('BK-77', Pratica::where('codice_pratica', 'QT1')->value('codice_pratica_banca'));
        $this->assertSame('BK-88', Pratica::where('codice_pratica', 'QT2')->value('codice_pratica_banca'));
        $this->assertSame(['Proforma'], Provvigione::pluck('stato')->unique()->all());
        $this->assertEquals(1384.5, Proforma::find($esito->proforma->id)->compenso);
        $this->assertSame('cliente', $esito->documento->documentable_type);
        $this->assertSame(DocumentType::where('code', 'PROFORMA_BANCA')->value('id'), $esito->documento->document_type_id);
        $this->assertStringContainsString('Nessuna difformità', $esito->documento->internal_notes);
        $this->assertSame(DocumentStatus::UPLOADED, $esito->documento->status);
        $this->assertNotNull($esito->documento->getFirstMedia('documents'));
    }

    public function test_differences_are_written_in_the_document_notes_and_no_proforma_is_created(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '150.00');
        $this->pratica('QT2', 'BNCLRA85M41F205Z', 'Bianchi', '200.00');

        $esito = $this->importa("Pratica;Codice fiscale;Provvigione\nBK-77;RSSMRA80A01H501U;150,00\nBK-88;BNCLRA85M41F205Z;180,00\nBK-99;VRDGPP70A01H501X;50,00\n");

        $this->assertFalse($esito->ok());
        $this->assertNull($esito->proforma);
        $this->assertCount(2, $esito->difformita);
        $this->assertStringContainsString('la banca riconosce € 180,00, la provvigione attiva è € 200,00', $esito->documento->internal_notes);
        $this->assertStringContainsString('nessuna provvigione attiva trovata', $esito->documento->internal_notes);
        $this->assertSame(DocumentStatus::ANOMALY, $esito->documento->status);
        $this->assertStringContainsString('la banca riconosce € 180,00', $esito->documento->rejection_note);
        $this->assertSame(['Inserito'], Provvigione::pluck('stato')->unique()->all());
        $this->assertSame('BK-77', Pratica::where('codice_pratica', 'QT1')->value('codice_pratica_banca'));
        $this->assertSame('BK-88', Pratica::where('codice_pratica', 'QT2')->value('codice_pratica_banca'));
        $this->assertSame(0, Proforma::count());
        $this->assertStringContainsString('proforma non creato e provvigioni non associate', $esito->documento->internal_notes);
    }

    public function test_a_file_that_matches_no_active_commission_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impossibile dedurre la banca');

        $this->importa("Pratica;Provvigione\nZZ1;10,00\n");
    }

    public function test_images_are_read_by_the_ai_reader(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '150.00');

        $lettore = new class extends LettoreProformaBanca
        {
            protected function leggiConAi(string $contenuto, string $mime): array
            {
                return [new RigaProformaBanca(150.0, 'QT1', null, 'Rossi')];
            }
        };

        $esito = (new ImportaProformaBanca($lettore))->importa('immagine', 'dettaglio.png', 'image/png', '2026-10-28');

        $this->assertTrue($esito->ok());
        $this->assertSame('dettaglio.png', $esito->documento->name);
    }

    public function test_excel_files_are_read_by_column_headers(): void
    {
        config(['excel.temporary_files.local_path' => sys_get_temp_dir()]);

        $xlsx = Excel::raw(new class implements FromArray
        {
            public function array(): array
            {
                return [['Estratto provvigioni'], ['Nominativo', 'Contratto', 'Compenso'], ['Rossi Mario', 'BK-1', 150.5], ['Totale', null, 150.5]];
            }
        }, ExcelWriter::XLSX);

        $righe = (new LettoreProformaBanca)->leggi($xlsx, 'dettaglio.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertCount(1, $righe);
        $this->assertSame(['BK-1', 'Rossi', 'Mario', 150.5], [$righe[0]->codicePratica, $righe[0]->cognome, $righe[0]->nome, $righe[0]->importo]);
    }

    public function test_the_ai_answer_is_deserialized_into_rows_that_pass_validation(): void
    {
        $elenco = Deserializer::make()->fromJson('{"righe":[{"codice_pratica":"A1","cognome":"Rossi","importo":12.5}]}', ElencoProformaBanca::class);

        $this->assertInstanceOf(RigaLettaDallAi::class, $elenco->righe[0]);
        $this->assertSame([], Validator::validate($elenco));
    }

    public function test_a_code_misread_from_a_photo_still_matches(): void
    {
        $this->pratica('5062644', 'RSSMRA80A01H501U', 'Rizzo', '646.80');

        $esito = (new ImportaProformaBanca(new class extends LettoreProformaBanca
        {
            protected function leggiConAi(string $contenuto, string $mime): array
            {
                return [new RigaProformaBanca(646.8, 'S062644', null, 'Rizzo')];
            }
        }))->importa('immagine', 'foto.jpeg', 'image/jpeg', '2026-10-28');

        $this->assertTrue($esito->ok());
        $this->assertSame(1, $esito->abbinate);
    }

    public function test_rows_match_by_name_when_the_pratica_has_surname_and_name_swapped_and_the_commission_has_neither(): void
    {
        $this->pratica('QT1', 'TCCMLE62E70F839T', 'EMILIA', '987.36', 'TUCCILLO');
        $this->pratica('QT2', 'RZZRFL58M31I119N', 'RIZZO', '646.80', 'RAFFAELE');

        $esito = (new ImportaProformaBanca(new class extends LettoreProformaBanca
        {
            protected function leggiConAi(string $contenuto, string $mime): array
            {
                return [
                    new RigaProformaBanca(987.36, '5055860', null, 'TUCCILLO', 'EMILIA'),
                    new RigaProformaBanca(646.8, '5062644', null, 'RAFFAELE RIZZO'),
                    new RigaProformaBanca(-1916.64, '5053880', null, 'STORNO BUZZERIO', 'ARMANDO'),
                ];
            }
        }))->importa('immagine', 'foto.jpeg', 'image/jpeg', '2026-10-28');

        $this->assertSame(2, $esito->abbinate);
        $this->assertCount(1, $esito->difformita);
        $this->assertSame('5055860', Pratica::where('codice_pratica', 'QT1')->value('codice_pratica_banca'));
        $this->assertSame('5062644', Pratica::where('codice_pratica', 'QT2')->value('codice_pratica_banca'));
    }

    private function importaFoto(array $righe): EsitoImportProformaBanca
    {
        $lettore = new class($righe) extends LettoreProformaBanca
        {
            public function __construct(private array $righe) {}

            protected function leggiConAi(string $contenuto, string $mime): array
            {
                return $this->righe;
            }
        };

        return (new ImportaProformaBanca($lettore))->importa('x', 'foto.jpeg', 'image/jpeg', '2026-10-28');
    }

    public function test_a_reversal_of_a_paid_commission_matches_the_negative_active_row(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '100.00', 'Mario');
        $this->pratica('QT7011', 'BZZRND60A01H501X', 'ARMANDO', '1916.64', 'BUZZERIO');
        Provvigione::query()->where('id', 'QT7011')->update(['stato' => 'Pagato']);
        Provvigione::unguarded(fn () => Provvigione::query()->create([
            'id' => 'QT7011-', 'id_pratica' => 'QT7011', 'importo' => '-1916.64', 'entrata_uscita' => 'Entrata',
            'stato' => 'Inserito', 'status_compenso' => 'Pratica stornata', 'istituto_finanziario' => 'Banca Alfa',
        ]));

        $esito = $this->importaFoto([
            new RigaProformaBanca(100.0, 'A1', null, 'Rossi', 'Mario'),
            new RigaProformaBanca(-1916.64, '5053880', null, 'STORNO BUZZERIO', 'ARMANDO'),
        ]);

        $this->assertTrue($esito->ok());
        $this->assertSame(2, $esito->abbinate);
        $this->assertEquals(-1816.64, Proforma::find($esito->proforma->id)->compenso);
        $this->assertSame('Pagato', Provvigione::find('QT7011')->stato);
    }

    public function test_a_reversal_without_a_negative_row_adds_it_to_the_commissions_like_an_import_would(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '100.00', 'Mario');
        $this->pratica('QT7011', 'BZZRND60A01H501X', 'ARMANDO', '1916.64', 'BUZZERIO');
        Provvigione::query()->where('id', 'QT7011')->update(['stato' => 'Pagato']);
        $righe = [
            new RigaProformaBanca(100.0, 'A1', null, 'Rossi', 'Mario'),
            new RigaProformaBanca(-1916.64, '5053880', null, 'STORNO BUZZERIO', 'ARMANDO'),
        ];

        $esito = $this->importaFoto($righe);

        $storno = Provvigione::find('QT7011-');
        $this->assertTrue($esito->ok());
        $this->assertEquals(-1916.64, $storno->importo);
        $this->assertSame('Pratica stornata', $storno->status_compenso);
        $this->assertSame('Storno provvigione', $storno->descrizione);
        $this->assertSame('QT7011', $storno->id_pratica);
        $this->assertSame('Banca Alfa', $storno->istituto_finanziario);
        $this->assertSame('Proforma', $storno->stato);
        $this->assertSame($esito->proforma->id, $storno->proforma_id);
        $this->assertSame('Pagato', Provvigione::find('QT7011')->stato);
        $this->assertEquals(-1816.64, Proforma::find($esito->proforma->id)->compenso);
        $this->assertSame('5053880', Pratica::where('codice_pratica', 'QT7011')->value('codice_pratica_banca'));
    }

    public function test_a_reversal_already_registered_and_no_longer_active_is_not_duplicated(): void
    {
        $this->pratica('QT1', 'RSSMRA80A01H501U', 'Rossi', '100.00', 'Mario');
        $this->pratica('QT7011', 'BZZRND60A01H501X', 'ARMANDO', '1916.64', 'BUZZERIO');
        Provvigione::unguarded(fn () => Provvigione::query()->create([
            'id' => 'QT7011-', 'id_pratica' => 'QT7011', 'importo' => '-1916.64', 'entrata_uscita' => 'Entrata',
            'stato' => 'Pagato', 'istituto_finanziario' => 'Banca Alfa',
        ]));

        $esito = $this->importaFoto([
            new RigaProformaBanca(100.0, 'A1', null, 'Rossi', 'Mario'),
            new RigaProformaBanca(-1916.64, '5053880', null, 'STORNO BUZZERIO', 'ARMANDO'),
        ]);

        $this->assertFalse($esito->ok());
        $this->assertStringContainsString('già registrato', $esito->difformita[0]);
        $this->assertSame(1, Provvigione::where('id_pratica', 'QT7011')->where('importo', '<', 0)->count());
    }

    public function test_the_documents_tab_shows_a_warning_with_the_notes_only_when_the_document_has_them(): void
    {
        $this->actingAs(User::factory()->create());
        $banca = Clienti::query()->where('name', 'Banca Alfa')->first();
        $crea = fn (string $nome, ?string $note): Document => Document::query()->create([
            'documentable_type' => 'cliente', 'documentable_id' => (string) $banca->getKey(), 'name' => $nome, 'internal_notes' => $note,
        ]);
        $conNote = $crea('con-note.csv', 'Riga 5: la banca riconosce € 840,00');
        $senzaNote = $crea('senza-note.csv', null);
        $storico = $crea('storico.csv', null);
        $storico->forceFill(['documentable_type' => 'clienti'])->save();
        $this->assertCount(3, $banca->documents);
        $this->assertSame('cliente', $banca->documents()->create(['name' => 'nuovo.csv'])->documentable_type);

        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $banca, 'pageClass' => EditClienti::class])
            ->assertTableColumnStateSet('annotazioni', 'annotazioni', $conNote)
            ->assertTableColumnStateSet('annotazioni', null, $senzaNote)
            ->assertSee('Riga 5: la banca riconosce € 840,00', escape: false);
    }

    public function test_numbers_are_read_in_italian_and_english_format(): void
    {
        $this->assertSame(1234.56, LettoreProformaBanca::numero('1.234,56'));
        $this->assertSame(1234.56, LettoreProformaBanca::numero('€ 1,234.56'));
        $this->assertSame(12.5, LettoreProformaBanca::numero('12,5'));
        $this->assertSame(1234000.0, LettoreProformaBanca::numero('1.234.000'));
        $this->assertNull(LettoreProformaBanca::numero('n.d.'));
    }
}
