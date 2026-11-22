<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libro mayor de doble partida (append-only), numeración, reversiones, conversiones, cotizaciones,
 * sincronización y auditoría. Las protecciones en base de datos (CHECK y triggers) se instalan solo en MySQL/MariaDB.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $t) {
            $t->char('id', 36)->primary();                      // agrupa asientos vinculados (conversión, transferencia entre espacios)
            $t->char('user_id', 36);
            $t->string('kind', 30);                             // income|expense|transfer|interspace_transfer|conversion|opening|reversal
            $t->dateTime('occurred_at', 3);
            $t->char('device_id', 36)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->char('operation_id', 36)->nullable();
            $t->string('number', 20)->nullable();               // PER-2026-000001; se asigna al confirmar
            // "Revertido" NO es un estado almacenado: se deriva de v_entry_reversal (supuesto S-1).
            $t->enum('status', ['draft', 'pending_review', 'confirmed'])->default('draft');
            $t->enum('kind', ['ordinary', 'opening', 'fx', 'adjustment', 'reversal'])->default('ordinary');
            $t->string('currency', 10);
            $t->dateTime('occurred_at', 3);                      // cuándo ocurrió
            $t->dateTime('recorded_at', 3);                      // cuándo ingresó al sistema
            $t->dateTime('confirmed_at', 3)->nullable();         // cuándo se confirmó contablemente
            $t->date('accounting_date')->nullable();             // fecha contable efectiva (define el período)
            $t->date('accounting_date_requested')->nullable();   // si se desplazó a otro período, la fecha original pedida
            $t->char('period_id', 36)->nullable();
            $t->char('reverses_entry_id', 36)->nullable();
            $t->text('reversal_reason')->nullable();
            $t->string('description', 500)->default('');
            $t->json('validation_errors')->nullable();           // motivo por el que quedó pendiente de revisión
            $t->char('created_by', 36);
            $t->char('device_id', 36)->nullable();
            $t->char('sync_op_id', 36)->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();

            $t->unique(['space_id', 'number']);                  // los NULL no colisionan: borradores sin número
            $t->unique(['id', 'space_id']);
            $t->index(['space_id', 'status', 'accounting_date']);
            $t->index(['space_id', 'occurred_at']);
            $t->index('operation_id');
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
            $t->foreign('operation_id')->references('id')->on('operations')->restrictOnDelete();
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['period_id', 'space_id'])->references(['id', 'space_id'])->on('accounting_periods')->restrictOnDelete();
            $t->foreign(['reverses_entry_id', 'space_id'])->references(['id', 'space_id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('entry_id', 36);
            $t->char('space_id', 36);                            // redundante a propósito: permite FK compuestas por espacio
            $t->unsignedSmallInteger('line_no');
            $t->char('ledger_account_id', 36);
            $t->decimal('debit', 19, 4)->default(0);
            $t->decimal('credit', 19, 4)->default(0);
            $t->char('category_id', 36)->nullable();
            $t->string('memo', 255)->nullable();
            $t->unique(['entry_id', 'line_no']);
            $t->index(['space_id', 'ledger_account_id']);
            $t->foreign(['entry_id', 'space_id'])->references(['id', 'space_id'])->on('journal_entries')->restrictOnDelete();
            $t->foreign(['ledger_account_id', 'space_id'])->references(['id', 'space_id'])->on('ledger_accounts')->restrictOnDelete();
            $t->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        // Cuánto de cada apunte original ya fue revertido (permite reversiones parciales sin mutar el apunte).
        Schema::create('reversal_allocations', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('reversal_entry_id', 36);
            $t->char('original_line_id', 36);
            $t->decimal('amount', 19, 4);
            $t->dateTime('created_at', 3)->nullable();
            $t->index('original_line_id');
            $t->foreign('reversal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $t->foreign('original_line_id')->references('id')->on('journal_lines')->restrictOnDelete();
        });

        // Numeración correlativa por espacio y año. Se incrementa con SELECT ... FOR UPDATE dentro de la transacción de confirmación.
        Schema::create('entry_sequences', function (Blueprint $t) {
            $t->char('space_id', 36);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number')->default(0);
            $t->primary(['space_id', 'year']);
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
        });

        // Caché reconstruible desde el ledger; nunca es fuente de verdad (spec §8.3).
        Schema::create('balance_snapshots', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->char('ledger_account_id', 36);
            $t->string('currency', 10);
            $t->decimal('balance', 19, 4);                       // debe − haber
            $t->char('last_entry_id', 36)->nullable();
            $t->dateTime('computed_at', 3);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['ledger_account_id', 'currency']);
            $t->foreign(['ledger_account_id', 'space_id'])->references(['id', 'space_id'])->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('base_currency', 10);
            $t->string('quote_currency', 10);
            $t->enum('rate_type', ['oficial', 'bna', 'mep', 'ccl', 'blue', 'crypto', 'custom']);
            $t->decimal('rate', 20, 8);
            $t->decimal('buy', 20, 8)->nullable();
            $t->decimal('sell', 20, 8)->nullable();
            $t->string('source', 120);
            $t->enum('origin', ['manual', 'auto'])->default('manual');
            $t->dateTime('quoted_at', 3);
            $t->char('created_by', 36)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->index(['base_currency', 'quote_currency', 'rate_type', 'quoted_at'], 'idx_rates_lookup');
            $t->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->foreign('quote_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        // Cada conversión conserva tasa, fuente, fecha y monedas (spec §5.11, §7.1).
        Schema::create('fx_conversions', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('operation_id', 36);
            $t->char('space_id', 36);
            $t->char('source_entry_id', 36);
            $t->char('target_entry_id', 36);
            $t->char('commission_entry_id', 36)->nullable();
            $t->string('from_currency', 10);
            $t->decimal('from_amount', 19, 4);
            $t->string('to_currency', 10);
            $t->decimal('to_amount', 19, 4);
            $t->decimal('rate', 20, 8);
            $t->string('rate_type', 20)->nullable();
            $t->string('rate_source', 120)->nullable();
            $t->dateTime('rate_quoted_at', 3)->nullable();
            $t->char('exchange_rate_id', 36)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->foreign('operation_id')->references('id')->on('operations')->restrictOnDelete();
            $t->foreign('source_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $t->foreign('target_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $t->foreign('commission_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $t->foreign('exchange_rate_id')->references('id')->on('exchange_rates')->restrictOnDelete();
        });

        // Idempotencia de sincronización: el id de la operación (generado en el dispositivo) es único.
        Schema::create('sync_operations', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('user_id', 36);
            $t->char('device_id', 36);
            $t->char('space_id', 36)->nullable();
            $t->string('entity', 40);
            $t->char('entity_id', 36)->nullable();
            $t->string('action', 20);
            $t->json('payload');
            $t->unsignedInteger('base_version')->nullable();
            $t->dateTime('client_created_at', 3);
            $t->dateTime('received_at', 3);
            $t->enum('status', ['applied', 'pending_review', 'conflict', 'rejected']);
            $t->json('result')->nullable();                      // referencia al resultado del servidor (ids, números de asiento)
            $t->text('error')->nullable();
            $t->index(['user_id', 'received_at']);
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->restrictOnDelete();
        });

        Schema::create('sync_conflicts', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('sync_operation_id', 36);
            $t->char('space_id', 36)->nullable();
            $t->string('entity', 40);
            $t->char('entity_id', 36)->nullable();
            $t->json('server_state')->nullable();               // se conservan ambas versiones; nunca last-write-wins
            $t->json('client_state');
            $t->enum('status', ['open', 'resolved'])->default('open');
            $t->string('resolution', 30)->nullable();           // keep_server | keep_client | merged
            $t->char('resolved_by', 36)->nullable();
            $t->dateTime('resolved_at', 3)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->foreign('sync_operation_id')->references('id')->on('sync_operations')->restrictOnDelete();
        });

        Schema::create('tombstones', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36)->nullable();
            $t->string('entity', 40);
            $t->char('entity_id', 36);
            $t->unsignedInteger('version');
            $t->enum('action', ['archive', 'deactivate', 'delete']);
            $t->dateTime('deleted_at', 3);
            $t->unique(['entity', 'entity_id']);
            $t->index('deleted_at');
        });

        Schema::create('audit_log', function (Blueprint $t) {
            $t->bigIncrements('seq');                            // orden estricto para reconstruir la secuencia histórica
            $t->char('id', 36)->unique();
            $t->char('space_id', 36)->nullable();
            $t->char('user_id', 36)->nullable();
            $t->char('device_id', 36)->nullable();
            $t->string('entity', 40);
            $t->char('entity_id', 36)->nullable();
            $t->string('action', 40);
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->text('reason')->nullable();
            $t->json('related')->nullable();
            $t->enum('source', ['mobile', 'api', 'job', 'web'])->default('api');
            $t->dateTime('created_at', 3);
            $t->index(['entity', 'entity_id']);
            $t->index(['space_id', 'created_at']);
        });

        $this->createViews();

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->installMysqlProtections();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->dropMysqlProtections();
        }
        DB::statement('DROP VIEW IF EXISTS v_entry_reversal');
        DB::statement('DROP VIEW IF EXISTS v_ledger_balances');

        foreach (['audit_log', 'tombstones', 'sync_conflicts', 'sync_operations', 'fx_conversions', 'exchange_rates', 'balance_snapshots', 'entry_sequences', 'reversal_allocations', 'journal_lines', 'journal_entries', 'operations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createViews(): void
    {
        // Saldo (debe − haber) por cuenta contable, solo con asientos confirmados.
        DB::statement(<<<'SQL'
            CREATE VIEW v_ledger_balances AS
            SELECT l.space_id, l.ledger_account_id, e.currency,
                   SUM(l.debit) AS total_debit, SUM(l.credit) AS total_credit,
                   SUM(l.debit) - SUM(l.credit) AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.entry_id
            WHERE e.status = 'confirmed'
            GROUP BY l.space_id, l.ledger_account_id, e.currency
        SQL);

        // Estado derivado de reversión: none | partial | full (supuesto S-1: sin UPDATE sobre asientos confirmados).
        DB::statement(<<<'SQL'
            CREATE VIEW v_entry_reversal AS
            SELECT e.id AS entry_id, e.space_id,
                   (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.entry_id = e.id) AS total_amount,
                   (SELECT COALESCE(SUM(a.amount), 0)
                      FROM reversal_allocations a
                      JOIN journal_lines jl ON jl.id = a.original_line_id
                     WHERE jl.entry_id = e.id AND jl.debit > 0) AS reversed_amount
            FROM journal_entries e
            WHERE e.status = 'confirmed' AND e.kind <> 'reversal'
        SQL);
    }

    private function installMysqlProtections(): void
    {
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT chk_lines_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit + credit) > 0)");
        // Nota: la coherencia número/fecha contable/período al confirmar y la regla de reversión se validan en
        // triggers (no en CHECK) porque MySQL no permite CHECK sobre columnas usadas en claves foráneas.
        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT chk_rates_positive CHECK (rate > 0)");

        $signal = fn (string $msg) => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'";

        DB::unprepared(<<<SQL
            CREATE TRIGGER trg_entries_bu BEFORE UPDATE ON journal_entries FOR EACH ROW
            BEGIN
                DECLARE d DECIMAL(19,4);
                DECLARE c DECIMAL(19,4);
                DECLARE n INT;
                DECLARE p VARCHAR(10);
                IF OLD.status = 'confirmed' THEN
                    {$signal('LEDGER_IMMUTABLE: un asiento confirmado no puede modificarse')};
                END IF;
                IF (NEW.kind = 'reversal') <> (NEW.reverses_entry_id IS NOT NULL) THEN
                    {$signal('LEDGER_REVERSAL: solo los asientos de reversión referencian un asiento original')};
                END IF;
                IF NEW.status = 'confirmed' THEN
                    IF NEW.number IS NULL OR NEW.confirmed_at IS NULL OR NEW.accounting_date IS NULL OR NEW.period_id IS NULL THEN
                        {$signal('LEDGER_INCOMPLETE: falta número, fecha contable, período o fecha de confirmación')};
                    END IF;
                    SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0), COUNT(*) INTO d, c, n
                      FROM journal_lines WHERE entry_id = NEW.id;
                    IF n < 2 OR d <> c OR d = 0 THEN
                        {$signal('LEDGER_UNBALANCED: debe y haber no coinciden')};
                    END IF;
                    SELECT status INTO p FROM accounting_periods WHERE id = NEW.period_id;
                    IF p IS NULL OR p <> 'open' THEN
                        {$signal('PERIOD_CLOSED: el período contable no está abierto')};
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER trg_entries_bi BEFORE INSERT ON journal_entries FOR EACH ROW
            BEGIN
                IF NEW.status = 'confirmed' THEN
                    {$signal('LEDGER_FLOW: un asiento se crea como borrador o pendiente y se confirma después')};
                END IF;
                IF (NEW.kind = 'reversal') <> (NEW.reverses_entry_id IS NOT NULL) THEN
                    {$signal('LEDGER_REVERSAL: solo los asientos de reversión referencian un asiento original')};
                END IF;
            END
        SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER trg_entries_bd BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN
                IF OLD.status = 'confirmed' THEN
                    {$signal('LEDGER_IMMUTABLE: un asiento confirmado no puede eliminarse')};
                END IF;
            END
        SQL);

        foreach (['bi' => ['INSERT', 'NEW'], 'bu' => ['UPDATE', 'OLD'], 'bd' => ['DELETE', 'OLD']] as $suffix => [$event, $ref]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER trg_lines_{$suffix} BEFORE {$event} ON journal_lines FOR EACH ROW
                BEGIN
                    DECLARE s VARCHAR(20);
                    SELECT status INTO s FROM journal_entries WHERE id = {$ref}.entry_id;
                    IF s = 'confirmed' THEN
                        {$signal('LEDGER_IMMUTABLE: los apuntes de un asiento confirmado no pueden alterarse')};
                    END IF;
                END
            SQL);
        }

        foreach (['audit_log' => 'AUDIT_IMMUTABLE', 'reversal_allocations' => 'LEDGER_IMMUTABLE', 'exchange_rates' => 'RATES_IMMUTABLE'] as $table => $code) {
            foreach (['bu' => 'UPDATE', 'bd' => 'DELETE'] as $suffix => $event) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER trg_{$table}_{$suffix} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        {$signal($code . ': registro histórico inalterable')};
                    END
                SQL);
            }
        }
    }

    private function dropMysqlProtections(): void
    {
        foreach (['trg_entries_bi', 'trg_entries_bu', 'trg_entries_bd', 'trg_lines_bi', 'trg_lines_bu', 'trg_lines_bd',
                  'trg_audit_log_bu', 'trg_audit_log_bd', 'trg_reversal_allocations_bu', 'trg_reversal_allocations_bd',
                  'trg_exchange_rates_bu', 'trg_exchange_rates_bd'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
