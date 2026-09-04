<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;

final class BulkDeleteManager {
  public function __construct(private readonly Connection $database, private readonly QueueFactory $queues, private readonly TimeInterface $time, private readonly AccountProxyInterface $user, private readonly EntityTypeManagerInterface $entityTypes, private readonly LoggerInterface $logger) {}

  public function queryIds(array $f, int $after = 0, int $limit = 250): iterable {
    $q = $this->database->select('node_field_data', 'n')->fields('n', ['nid'])->distinct()->condition('n.nid', $after, '>')->orderBy('n.nid')->range(0, $limit);
    if (($f['status'] ?? '') !== '') $q->condition('n.status', (int) $f['status']);
    if (!empty($f['type'])) $q->condition('n.type', $f['type']);
    if (!empty($f['created_from'])) $q->condition('n.created', strtotime($f['created_from']), '>=');
    if (!empty($f['created_to'])) $q->condition('n.created', strtotime($f['created_to'] . ' 23:59:59'), '<=');
    if (!empty($f['modified_from'])) $q->condition('n.changed', strtotime($f['modified_from']), '>=');
    if (!empty($f['modified_to'])) $q->condition('n.changed', strtotime($f['modified_to'] . ' 23:59:59'), '<=');
    if (!empty($f['collection'])) {
      $q->join('node__field_member_of', 'm', 'm.entity_id=n.nid AND m.deleted=0');
      $q->condition('m.field_member_of_target_id', (int) $f['collection']);
    }
    if (!empty($f['taxonomy_field']) && preg_match('/^field_[a-z0-9_]+$/i', (string) $f['taxonomy_field']) && !empty($f['taxonomy_term'])) {
      $alias = 'tax_' . preg_replace('/[^a-z0-9_]/i', '', $f['taxonomy_field']);
      $q->join('node__' . $f['taxonomy_field'], $alias, "$alias.entity_id=n.nid AND $alias.deleted=0");
      $q->condition($alias . '.' . $f['taxonomy_field'] . '_target_id', (int) $f['taxonomy_term']);
    }
    foreach ($q->execute() as $row) yield (int) $row->nid;
  }

  public function countMatches(array $filters): int {
    $q = $this->database->select('node_field_data', 'n')->fields('n', ['nid'])->distinct();
    if (($filters['status'] ?? '') !== '') $q->condition('n.status', (int) $filters['status']);
    if (!empty($filters['type'])) $q->condition('n.type', $filters['type']);
    if (!empty($filters['created_from'])) $q->condition('n.created', strtotime($filters['created_from']), '>=');
    if (!empty($filters['created_to'])) $q->condition('n.created', strtotime($filters['created_to'] . ' 23:59:59'), '<=');
    if (!empty($filters['modified_from'])) $q->condition('n.changed', strtotime($filters['modified_from']), '>=');
    if (!empty($filters['modified_to'])) $q->condition('n.changed', strtotime($filters['modified_to'] . ' 23:59:59'), '<=');
    if (!empty($filters['collection'])) { $q->join('node__field_member_of', 'mc', 'mc.entity_id=n.nid AND mc.deleted=0'); $q->condition('mc.field_member_of_target_id', (int) $filters['collection']); }
    if (!empty($filters['taxonomy_field']) && preg_match('/^field_[a-z0-9_]+$/i', (string) $filters['taxonomy_field']) && !empty($filters['taxonomy_term'])) { $field = $filters['taxonomy_field']; $alias = 'tc_' . preg_replace('/[^a-z0-9_]/i', '', $field); $q->join('node__' . $field, $alias, "$alias.entity_id=n.nid AND $alias.deleted=0"); $q->condition($alias . '.' . $field . '_target_id', (int) $filters['taxonomy_term']); }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  public function start(array $filters): int {
    $total = $this->countMatches($filters);
    $job = (int) $this->database->insert('islandora_bulk_delete_job')->fields(['uid' => $this->user->id(), 'filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'total' => $total, 'pending' => $total, 'status' => $total ? 'discovering' : 'completed', 'started' => $this->time->getRequestTime()])->execute();
    if ($total) $this->queues->get('islandora_bulk_delete_discovery', TRUE)->createItem(['job_id' => $job, 'after' => 0]);
    return $job;
  }

  public function load(int $job): array|false {
    return $this->database->select('islandora_bulk_delete_job', 'j')->fields('j')->condition('id', $job)->execute()->fetchAssoc();
  }

  public function incrementQueued(int $job, int $amount): void {
    $this->database->update('islandora_bulk_delete_job')->expression('queued', 'queued + :amount', [':amount' => $amount])->condition('id', $job)->execute();
  }

  public function markDiscovered(int $job): void {
    $this->database->update('islandora_bulk_delete_job')->fields(['status' => 'queued'])->condition('id', $job)->execute();
  }

  public function record(int $job, int $nid, string $result, ?array $failure = NULL): void {
    if ($result === 'deleted') $this->database->update('islandora_bulk_delete_job')->expression('deleted', 'deleted + 1')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute();
    elseif ($result === 'failed') { $this->database->update('islandora_bulk_delete_job')->expression('failed', 'failed + 1')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute(); if ($failure) $this->database->insert('islandora_bulk_delete_failure')->fields($failure + ['job_id' => $job, 'nid' => $nid, 'created' => $this->time->getRequestTime()])->execute(); }
    else $this->database->update('islandora_bulk_delete_job')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute();
    $row = $this->database->select('islandora_bulk_delete_job', 'j')->fields('j', ['pending','failed'])->condition('id', $job)->execute()->fetchAssoc(); if ($row && (int) $row['pending'] === 0) $this->database->update('islandora_bulk_delete_job')->fields(['status' => ((int) $row['failed'] ? 'completed_with_failures' : 'completed'), 'finished' => $this->time->getRequestTime()])->condition('id', $job)->execute();
  }
}
