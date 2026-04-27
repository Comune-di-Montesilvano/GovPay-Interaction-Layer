<?php
namespace App\Database;

class UserGroupRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getPDO();
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT g.*, COUNT(DISTINCT m.user_id) AS member_count
             FROM user_groups g
             LEFT JOIN user_group_members m ON g.id = m.group_id
             GROUP BY g.id ORDER BY g.nome ASC'
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_groups WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $nome, ?string $descrizione, ?string $defaultIdEntrata): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_groups (nome, descrizione, default_id_entrata, created_at, updated_at)
             VALUES (:nome, :desc, :def, NOW(), NOW())'
        );
        $stmt->execute([':nome' => $nome, ':desc' => $descrizione, ':def' => $defaultIdEntrata]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $nome, ?string $descrizione, ?string $defaultIdEntrata): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_groups SET nome=:nome, descrizione=:desc,
             default_id_entrata=:def, updated_at=NOW() WHERE id=:id'
        );
        $stmt->execute([':nome' => $nome, ':desc' => $descrizione, ':def' => $defaultIdEntrata, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_groups WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @return int[] */
    public function getMemberIds(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM user_group_members WHERE group_id = :gid ORDER BY user_id'
        );
        $stmt->execute([':gid' => $groupId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'user_id');
    }

    public function setMembers(int $groupId, array $userIds): void
    {
        $this->pdo->prepare('DELETE FROM user_group_members WHERE group_id = :gid')
                  ->execute([':gid' => $groupId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO user_group_members (group_id, user_id) VALUES (:gid, :uid)'
        );
        foreach (array_map('intval', $userIds) as $uid) {
            $ins->execute([':gid' => $groupId, ':uid' => $uid]);
        }
    }

    /** @return string[] */
    public function getTipologie(int $groupId, string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_entrata FROM user_group_tipologie
             WHERE group_id = :gid AND id_dominio = :dom ORDER BY id_entrata'
        );
        $stmt->execute([':gid' => $groupId, ':dom' => $idDominio]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
    }

    public function setTipologie(int $groupId, string $idDominio, array $idEntrate): void
    {
        $this->pdo->prepare(
            'DELETE FROM user_group_tipologie WHERE group_id = :gid AND id_dominio = :dom'
        )->execute([':gid' => $groupId, ':dom' => $idDominio]);
        $ins = $this->pdo->prepare(
            'INSERT INTO user_group_tipologie (group_id, id_dominio, id_entrata)
             VALUES (:gid, :dom, :ent)'
        );
        foreach ($idEntrate as $ent) {
            $ins->execute([':gid' => $groupId, ':dom' => $idDominio, ':ent' => $ent]);
        }
    }

    /** @return int[] */
    public function getTemplateIds(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT template_id FROM user_group_templates WHERE group_id = :gid ORDER BY template_id'
        );
        $stmt->execute([':gid' => $groupId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'template_id');
    }

    public function setTemplates(int $groupId, array $templateIds): void
    {
        $this->pdo->prepare('DELETE FROM user_group_templates WHERE group_id = :gid')
                  ->execute([':gid' => $groupId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO user_group_templates (group_id, template_id) VALUES (:gid, :tid)'
        );
        foreach (array_map('intval', $templateIds) as $tid) {
            $ins->execute([':gid' => $groupId, ':tid' => $tid]);
        }
    }

    public function getGroupsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.nome, g.default_id_entrata
             FROM user_groups g
             JOIN user_group_members m ON g.id = m.group_id
             WHERE m.user_id = :uid ORDER BY g.nome'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    public function getTipologieForUser(int $userId, string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ugt.id_entrata
             FROM user_group_tipologie ugt
             JOIN user_group_members ugm ON ugm.group_id = ugt.group_id
             WHERE ugm.user_id = :uid AND ugt.id_dominio = :dom
             ORDER BY ugt.id_entrata'
        );
        $stmt->execute([':uid' => $userId, ':dom' => $idDominio]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
    }

    /** @return int[] */
    public function getTemplateIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ugt.template_id
             FROM user_group_templates ugt
             JOIN user_group_members ugm ON ugm.group_id = ugt.group_id
             WHERE ugm.user_id = :uid ORDER BY ugt.template_id'
        );
        $stmt->execute([':uid' => $userId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'template_id');
    }

    public function getDefaultTipologiaForUser(int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.default_id_entrata
             FROM user_groups g
             JOIN user_group_members m ON g.id = m.group_id
             WHERE m.user_id = :uid AND g.default_id_entrata IS NOT NULL
             ORDER BY g.nome LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['default_id_entrata'] : null;
    }

    /** @return int[] group_id values that have this template assigned */
    public function getGroupIdsForTemplate(int $templateId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT group_id FROM user_group_templates WHERE template_id = :tid ORDER BY group_id'
        );
        $stmt->execute([':tid' => $templateId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'group_id'));
    }

    /** Replaces which groups have this template (from the template side). */
    public function setGroupsForTemplate(int $templateId, array $groupIds): void
    {
        $this->pdo->prepare('DELETE FROM user_group_templates WHERE template_id = :tid')
                  ->execute([':tid' => $templateId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO user_group_templates (group_id, template_id) VALUES (:gid, :tid)'
        );
        foreach (array_map('intval', $groupIds) as $gid) {
            $ins->execute([':gid' => $gid, ':tid' => $templateId]);
        }
    }

    /** @return int[] group_id values the user belongs to */
    public function getMemberGroupIds(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT group_id FROM user_group_members WHERE user_id = :uid ORDER BY group_id'
        );
        $stmt->execute([':uid' => $userId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'group_id'));
    }

    /** Sets which groups the user belongs to (replaces all memberships for this user). */
    public function setGroupsForUser(int $userId, array $groupIds): void
    {
        $this->pdo->prepare('DELETE FROM user_group_members WHERE user_id = :uid')
                  ->execute([':uid' => $userId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO user_group_members (group_id, user_id) VALUES (:gid, :uid)'
        );
        foreach (array_map('intval', $groupIds) as $gid) {
            $ins->execute([':gid' => $gid, ':uid' => $userId]);
        }
    }
}
