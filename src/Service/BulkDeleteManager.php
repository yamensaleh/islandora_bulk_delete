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

  public function queryIds(array $f): iterable {
    $q = $this->database->select('node', 'n')->fields('n', ['nid'])->orderBy('n.nid');
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
    $ids = $this->queryIds($filters); $count = 0;
    foreach ($ids as $ignored) $count++;
    return $count;
  }

  public function start(array $filters): int {
    $ids = $this->queryIds($filters); $total = 0; $queue = $this->queues->get('islandora_bulk_delete', TRUE);
    $job = (int) $this->database->insert('islandora_bulk_delete_job')->fields(['uid' => $this->user->id(), 'filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'started' => $this->time->getRequestTime()])->execute();
    foreach ($ids as $nid) { $queue->createItem(['job_id' => $job, 'nid' => $nid]); $total++; }
    $this->database->update('islandora_bulk_delete_job')->fields(['total' => $total, 'queued' => $total, 'pending' => $total, 'status' => $total ? 'queued' : 'completed'])->condition('id', $job)->execute();
    return $job;
  }

  public function load(int $job): array|false {
    return $this->database->select('islandora_bulk_delete_job', 'j')->fields('j')->condition('id', $job)->execute()->fetchAssoc();
  }

  public function record(int $job, int $nid, string $result, ?array $failure = NULL): void {
    if ($result === 'deleted') $this->database->update('islandora_bulk_delete_job')->expression('deleted', 'deleted + 1')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute();
    elseif ($result === 'failed') { $this->database->update('islandora_bulk_delete_job')->expression('failed', 'failed + 1')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute(); if ($failure) $this->database->insert('islandora_bulk_delete_failure')->fields($failure + ['job_id' => $job, 'nid' => $nid, 'created' => $this->time->getRequestTime()])->execute(); }
    else $this->database->update('islandora_bulk_delete_job')->expression('pending', 'GREATEST(pending - 1, 0)')->condition('id', $job)->execute();
    $row = $this->database->select('islandora_bulk_delete_job', 'j')->fields('j', ['pending','failed'])->condition('id', $job)->execute()->fetchAssoc(); if ($row && (int) $row['pending'] === 0) $this->database->update('islandora_bulk_delete_job')->fields(['status' => ((int) $row['failed'] ? 'completed_with_failures' : 'completed'), 'finished' => $this->time->getRequestTime()])->condition('id', $job)->execute();
  }
}
