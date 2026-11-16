<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestCase;

class BankImportTest extends AuthTestCase
{
    private function treasury(): User
    {
        return $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    /** @param  array<int, string>  $lines */
    private function csv(array $lines, string $header = 'fecha;importe;concepto'): string
    {
        return implode("\n", [$header, ...$lines])."\n";
    }

    private function upload(string $content, string $name = 'extracto.csv'): TestResponse
    {
        return $this->post('/bank/imports', ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    public function test_anonymous_visitors_cannot_view_or_import(): void
    {
        $this->get('/bank')->assertRedirect('/login');
        $this->upload($this->csv(['01/03/2026;10,00;Cuota']))->assertRedirect('/login');

        $this->assertDatabaseCount('bank_movements', 0);
        $this->assertDatabaseCount('bank_imports', 0);
    }

    public function test_board_consults_movements_but_cannot_import(): void
    {
        $this->actingAs($this->treasury());
        $this->upload($this->csv(['01/03/2026;10,00;Cuota Ana']))->assertRedirect('/bank');

        $this->actingAs($this->board());
        $this->get('/bank')->assertOk()->assertSee('Cuota Ana')->assertDontSee('Importar extracto');
        $this->upload($this->csv(['02/03/2026;20,00;Otra']))->assertForbidden();

        $this->assertDatabaseCount('bank_movements', 1);
        $this->assertDatabaseCount('bank_imports', 1);
    }

    public function test_a_valid_file_creates_one_movement_per_row_and_an_import_log(): void
    {
        $user = $this->treasury();
        $this->actingAs($user);

        $this->upload($this->csv(['01/03/2026;10,00;Cuota Ana', '2026-03-02;-1234,5;Recibo luz']))->assertRedirect('/bank');

        $this->assertDatabaseCount('bank_movements', 2);
        $this->assertDatabaseHas('bank_movements', ['occurred_on' => '2026-03-01', 'amount_cents' => 1000, 'concept' => 'Cuota Ana']);
        $this->assertDatabaseHas('bank_movements', ['occurred_on' => '2026-03-02', 'amount_cents' => -123450, 'concept' => 'Recibo luz']);
        $this->assertDatabaseHas('bank_imports', ['user_id' => $user->id, 'imported_count' => 2, 'duplicate_count' => 0, 'rejected_count' => 0]);
        $this->assertSame(BankImport::first()->id, BankMovement::first()->bank_import_id);
    }

    public function test_reimporting_the_same_file_creates_nothing_and_reports_duplicates(): void
    {
        $this->actingAs($this->treasury());
        $content = $this->csv(['01/03/2026;10,00;Cuota Ana', '02/03/2026;20,00;Cuota Luis']);
        $this->upload($content);

        $this->upload($content)->assertRedirect('/bank');

        $this->assertDatabaseCount('bank_movements', 2);
        $this->assertDatabaseHas('bank_imports', ['imported_count' => 0, 'duplicate_count' => 2, 'rejected_count' => 0]);
    }

    public function test_identical_rows_inside_a_new_file_are_all_imported(): void
    {
        $this->actingAs($this->treasury());

        $this->upload($this->csv(['01/03/2026;30,00;Cuota', '01/03/2026;30,00;Cuota']));

        $this->assertDatabaseCount('bank_movements', 2);
        $this->assertDatabaseHas('bank_imports', ['imported_count' => 2, 'duplicate_count' => 0]);
    }

    public function test_duplicates_ignore_case_and_extra_whitespace(): void
    {
        $this->actingAs($this->treasury());
        $this->upload($this->csv(['01/03/2026;10,00;Cuota Ana García']));

        $this->upload($this->csv(['2026-03-01;10.00;  CUOTA   ana  garcía ']));

        $this->assertDatabaseCount('bank_movements', 1);
        $this->assertDatabaseHas('bank_imports', ['imported_count' => 0, 'duplicate_count' => 1]);
    }

    public function test_rows_differing_in_date_amount_or_concept_are_not_duplicates(): void
    {
        $this->actingAs($this->treasury());
        $this->upload($this->csv(['01/03/2026;10,00;Cuota']));

        $this->upload($this->csv(['02/03/2026;10,00;Cuota', '01/03/2026;11,00;Cuota', '01/03/2026;10,00;Cuota extra']));

        $this->assertDatabaseCount('bank_movements', 4);
    }

    public function test_invalid_rows_are_rejected_with_line_and_reason_while_valid_ones_are_imported(): void
    {
        $this->actingAs($this->treasury());

        $response = $this->followingRedirects()->upload($this->csv([
            '01/03/2026;10,00;Cuota válida',
            '31/02/2026;10,00;Fecha imposible',
            '01/03/2026;diez;Importe no numérico',
            '01/03/2026;0;Importe cero',
            '01/03/2026;10,123;Demasiados decimales',
            '01/03/2026;10,00;',
            '01/03/2026;10,00',
            '',
            '03/03/2026;5,00;Otra válida',
        ]));

        $response->assertOk();
        $response->assertSee('Línea 3');
        $response->assertSee('Línea 4');
        $response->assertSee('Línea 5');
        $response->assertSee('Línea 6');
        $response->assertSee('Línea 7');
        $response->assertSee('Línea 8');
        $response->assertDontSee('Línea 9');
        $this->assertDatabaseCount('bank_movements', 2);
        $this->assertDatabaseHas('bank_imports', ['imported_count' => 2, 'duplicate_count' => 0, 'rejected_count' => 6]);
    }

    public function test_a_concept_longer_than_255_characters_is_rejected(): void
    {
        $this->actingAs($this->treasury());

        $this->upload($this->csv(['01/03/2026;10,00;'.str_repeat('a', 256)]));

        $this->assertDatabaseCount('bank_movements', 0);
        $this->assertDatabaseHas('bank_imports', ['rejected_count' => 1]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableFiles(): array
    {
        return [
            'empty file' => ['', 'extracto.csv'],
            'only a header' => ["fecha;importe;concepto\n", 'extracto.csv'],
            'missing a column' => ["fecha;importe\n01/03/2026;10,00\n", 'extracto.csv'],
            'unknown columns' => ["a;b;c\n1;2;3\n", 'extracto.csv'],
            'not a csv' => ["fecha;importe;concepto\n01/03/2026;10,00;Cuota\n", 'extracto.pdf'],
        ];
    }

    #[DataProvider('unusableFiles')]
    public function test_an_unusable_file_is_rejected_without_importing_anything(string $content, string $name): void
    {
        $this->actingAs($this->treasury());

        $this->upload($content, $name)->assertSessionHasErrors('file');

        $this->assertDatabaseCount('bank_movements', 0);
        $this->assertDatabaseCount('bank_imports', 0);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->actingAs($this->treasury());

        $this->post('/bank/imports', [])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('bank_imports', 0);
    }

    public function test_commas_headers_with_bom_and_latin1_encoding_are_accepted(): void
    {
        $this->actingAs($this->treasury());
        $content = "\u{FEFF}Fecha,Importe,Concepto\n01/03/2026,\"10,50\",\"Cuota, socia\"\n";
        $this->upload($content);

        $latin1 = mb_convert_encoding("fecha;importe;concepto\n02/03/2026;20,00;Cuota Muñoz\n", 'ISO-8859-1', 'UTF-8');
        $this->upload($latin1);

        $this->assertDatabaseHas('bank_movements', ['amount_cents' => 1050, 'concept' => 'Cuota, socia']);
        $this->assertDatabaseHas('bank_movements', ['amount_cents' => 2000, 'concept' => 'Cuota Muñoz']);
    }

    public function test_concepts_are_escaped_when_listed(): void
    {
        $this->actingAs($this->treasury());
        $this->upload($this->csv(['01/03/2026;10,00;<script>alert(1)</script>']));

        $this->get('/bank')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_the_original_file_is_not_stored(): void
    {
        Storage::fake('local');
        $this->actingAs($this->treasury());

        $this->upload($this->csv(['01/03/2026;10,00;Cuota']));

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], array_intersect(Schema::getColumnListing('bank_imports'), ['filename', 'original_name', 'path', 'content']));
    }

    public function test_the_import_log_lists_date_user_and_counts(): void
    {
        $this->actingAs($this->treasury());
        $this->upload($this->csv(['01/03/2026;10,00;Cuota', '32/03/2026;10,00;Mala']));

        $this->get('/bank')->assertOk()->assertSee('tesoreria@example.test');
    }
}
