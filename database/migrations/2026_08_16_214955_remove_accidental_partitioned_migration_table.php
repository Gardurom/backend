<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'partitioned_student_positions_and_geofence_events';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $rowCount = DB::table(self::TABLE)->count();

        if ($rowCount !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'La tabla accidental [%s] contiene %d fila(s). No se eliminará automáticamente.',
                    self::TABLE,
                    $rowCount
                )
            );
        }

        $columns = DB::select(
            <<<'SQL'
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = ?
                ORDER BY ordinal_position
            SQL,
            [self::TABLE]
        );

        $actualColumns = array_map(
            static fn (object $column): string => $column->column_name,
            $columns
        );

        $expectedColumns = [
            'id',
            'created_at',
            'updated_at',
        ];

        if ($actualColumns !== $expectedColumns) {
            throw new \RuntimeException(
                sprintf(
                    'La tabla accidental [%s] no tiene exactamente las columnas esperadas. Encontradas: [%s]. No se eliminará.',
                    self::TABLE,
                    implode(', ', $actualColumns)
                )
            );
        }

        Schema::drop(self::TABLE);
    }

    public function down(): void
    {
        // Esta migración elimina una tabla accidental y vacía.
        // No se recrea durante rollback para evitar reintroducir
        // una estructura que nunca debió formar parte del esquema.
    }
};