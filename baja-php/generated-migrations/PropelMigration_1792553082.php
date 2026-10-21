<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand, like PropelMigration_1792553081, which this follows.
 *
 * Everything the lap counting tables gained after they first shipped
 * (90ead59), as plain ALTERs: MySQL 8 has no ADD COLUMN IF NOT EXISTS, and
 * _1792553081 guarantees the 90ead59 shape on every server, created there
 * or already present. Each column goes where schema.xml and
 * baja-infra/mysql/init/06-contagem.sql have it (the AFTER clauses), so a
 * migrated database and a fresh dev database end up with the same SHOW
 * CREATE TABLE.
 *
 *   contagem_fonte     papel, papel_desde_ms, periodos_fora (observer
 *                      sources and the periods a source did not count),
 *                      imagem_base_url (automatic sources)
 *   contagem_passagem  equipe_numero becomes nullable (a "??" and a timing
 *                      sensor mark have no car), imagem_ref and confianca
 *                      (automatic sources), and the index the per-upload
 *                      recount reads the entries with no car through
 *   contagem_decisao   passagem_alvo_id (the "usar_tempo" decision)
 *
 * No row is changed: every new column is NULL or has a default, and making
 * a column nullable keeps what it holds.
 *
 * A server that already has some of these columns (one that ran a later
 * copy of the old hand-run 2026-contagem.sql) cannot run this: the ADD of a
 * column it has fails. Check before migrating, bring such a server up to
 * date by hand and record this version as applied there instead:
 * docs/contagem.md, Deploy notes.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1792553082.
 */
class PropelMigration_1792553082{
    /**
     * @var string
     */
    public $comment = 'contagem: columns and index added since 90ead59';

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function preUp(MigrationManager $manager)
    {
        // add the pre-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function postUp(MigrationManager $manager)
    {
        // add the post-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function preDown(MigrationManager $manager)
    {
        // add the pre-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function postDown(MigrationManager $manager)
    {
        // add the post-migration code here
    }

    /**
     * Get the SQL statements for the Up migration
     *
     * One ALTER per table, so each table changes all at once or not at all.
     * contagem_decisao goes first because passagem_alvo_id is the oldest of
     * these additions, so a database that took any later copy of the old
     * hand-run file usually stops on the first statement, before anything
     * has changed. Usually, not always: that column also came from a
     * separate file for a while. The check in docs/contagem.md looks for
     * every one of these columns, and is the thing to rely on.
     *
     * @return array list of the SQL strings to execute for the Up migration
     *               the keys being the datasources
     */
    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `contagem_decisao`
  ADD COLUMN `passagem_alvo_id` bigint DEFAULT NULL AFTER `passagem_id`;

ALTER TABLE `contagem_fonte`
  ADD COLUMN `papel` varchar(12) NOT NULL DEFAULT 'votante' AFTER `desativada_em_ms`,
  ADD COLUMN `papel_desde_ms` bigint DEFAULT NULL AFTER `papel`,
  ADD COLUMN `periodos_fora` text AFTER `papel_desde_ms`,
  ADD COLUMN `imagem_base_url` varchar(255) DEFAULT NULL AFTER `periodos_fora`;

ALTER TABLE `contagem_passagem`
  MODIFY `equipe_numero` int DEFAULT NULL,
  ADD COLUMN `imagem_ref` varchar(64) CHARACTER SET ascii DEFAULT NULL AFTER `recebido_em_ms`,
  ADD COLUMN `confianca` decimal(4,3) DEFAULT NULL AFTER `imagem_ref`,
  ADD KEY `contagem_passagem_equipe_fonte_idx` (`equipe_numero`,`fonte_id`,`ts_ms`);
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * Drops what Up added, and with it what those columns held: observer
     * roles, image links and confidences, and every "usar_tempo" pairing.
     *
     * equipe_numero goes back to NOT NULL first, and refuses to while any
     * entry has no car ("??", sensor marks): MySQL will not invent a number
     * for them. That statement is first so that, if it fails, nothing has
     * been dropped yet. Delete those entries on purpose before going down.
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `contagem_passagem`
  MODIFY `equipe_numero` int NOT NULL,
  DROP KEY `contagem_passagem_equipe_fonte_idx`,
  DROP COLUMN `confianca`,
  DROP COLUMN `imagem_ref`;

ALTER TABLE `contagem_decisao`
  DROP COLUMN `passagem_alvo_id`;

ALTER TABLE `contagem_fonte`
  DROP COLUMN `imagem_base_url`,
  DROP COLUMN `periodos_fora`,
  DROP COLUMN `papel_desde_ms`,
  DROP COLUMN `papel`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
