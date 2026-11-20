<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand, like PropelMigration_1793669096, for the reason
 * PropelMigration_1787507406 records: the dev database has drifted from
 * schema.xml in ways that predate this branch, and `migration:diff` emits
 * all of it.
 *
 * Adds certificado_requerimento.resposta and .respondido_em (BAJAAPP-88):
 * the reply staff send the participant when they close a report, and when
 * the relay accepted that email. schema.xml explains both columns;
 * Baja\Certificado\Requerimento\Fechamento is what writes them.
 *
 * No row is changed. Reports already closed keep `resolucao` as their
 * internal note and have no reply, which is the truth: nobody wrote them
 * one through this system. Their status page shows the status alone, and
 * the staff page offers no "Reenviar", because there is nothing to resend.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1793747906.
 */
class PropelMigration_1793747906{
    /**
     * @var string
     */
    public $comment = 'certificado_requerimento.resposta and respondido_em: the reply to the participant (BAJAAPP-88)';

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
     * @return array list of the SQL strings to execute for the Up migration
     *               the keys being the datasources
     */
    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `certificado_requerimento`
    ADD COLUMN `resposta` VARCHAR(1000) NULL AFTER `duplicado_de`,
    ADD COLUMN `respondido_em` DATETIME NULL AFTER `resposta`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * Drops every reply staff wrote through the status page. The reports
     * stay, with their status and internal note.
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `certificado_requerimento`
    DROP COLUMN `respondido_em`,
    DROP COLUMN `resposta`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
