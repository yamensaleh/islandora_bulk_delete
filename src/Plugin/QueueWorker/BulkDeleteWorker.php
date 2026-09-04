<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Plugin\QueueWorker;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_bulk_delete\Service\BulkDeleteManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
/** @QueueWorker(id="islandora_bulk_delete", title=@Translation("Islandora bulk deletion"), cron={"time"=60}) */
final class BulkDeleteWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly EntityTypeManagerInterface $entities, private readonly BulkDeleteManager $manager, private readonly LoggerInterface $logger) { parent::__construct($configuration, $plugin_id, $plugin_definition); }
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static { return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('islandora_bulk_delete.manager'), $container->get('logger.channel.islandora_bulk_delete')); }
  public function processItem($data): void {
    $job = (int) $data['job_id']; $nid = (int) $data['nid']; $node = $this->entities->getStorage('node')->load($nid);
    if (!$node) { $this->manager->record($job, $nid, 'missing'); return; }
    try { $uuid = $node->uuid(); $title = (string) $node->label(); $node->delete(); $this->manager->record($job, $nid, 'deleted'); $this->logger->info('Deleted node {nid} ({uuid}) {title}', ['nid' => $nid, 'uuid' => $uuid, 'title' => $title]); }
    catch (\Throwable $e) { $this->logger->error('Failed deleting node {nid} ({uuid}) {title}: {message}', ['nid' => $nid, 'uuid' => $node->uuid(), 'title' => $node->label(), 'message' => $e->getMessage()]); $this->manager->record($job, $nid, 'failed', ['uuid' => $node->uuid(), 'title' => $node->label(), 'message' => $e->getMessage()]); }
  }
}
