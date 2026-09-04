<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Plugin\QueueWorker;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\islandora_bulk_delete\Service\BulkDeleteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
/** @QueueWorker(id="islandora_bulk_delete_discovery", title=@Translation("Bulk deletion ID discovery"), cron={"time"=60}) */
final class BulkDeleteDiscoveryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly BulkDeleteManager $manager, private readonly QueueFactory $queues) { parent::__construct($configuration, $plugin_id, $plugin_definition); }
  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition): static { return new static($configuration, $plugin_id, $plugin_definition, $c->get('islandora_bulk_delete.manager'), $c->get('queue')); }
  public function processItem($data): void {
    $job = $this->manager->load((int) $data['job_id']); if (!$job || $job['status'] === 'cancelled') return;
    $filters = json_decode((string) $job['filters'], TRUE, 512, JSON_THROW_ON_ERROR); $ids = iterator_to_array($this->manager->queryIds($filters, (int) ($data['after'] ?? 0), 250)); $delete = $this->queues->get('islandora_bulk_delete', TRUE); $last = 0;
    foreach ($ids as $nid) { $delete->createItem(['job_id' => (int) $data['job_id'], 'nid' => $nid]); $last = $nid; }
    if ($last) { $this->manager->incrementQueued((int) $data['job_id'], count($ids)); $this->queues->get('islandora_bulk_delete_discovery', TRUE)->createItem(['job_id' => (int) $data['job_id'], 'after' => $last]); }
    else $this->manager->markDiscovered((int) $data['job_id']);
  }
}
