<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Commands;
use Drush\Commands\DrushCommands;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
final class BulkDeleteCommands extends DrushCommands {
  public function __construct(private readonly QueueFactory $queues) { parent::__construct(); }
  public static function create(ContainerInterface $c): static { return new static($c->get('queue')); }
  /**
   * Process queued Islandora deletions.
   *
   * @command islandora:bulk-delete:process
   * @option limit
   *   Maximum items to process.
   * @option time-limit
   *   Maximum execution time in seconds.
   */
  public function process(array $options = ['limit' => 100, 'time-limit' => 50]): void {
    $manager = \Drupal::service('plugin.manager.queue_worker');
    $limit = (int) ($options['limit'] ?? 100);
    $timeLimit = (int) ($options['time-limit'] ?? 50);
    $done = 0;
    $end = microtime(TRUE) + $timeLimit;
    foreach (['islandora_bulk_delete_discovery', 'islandora_bulk_delete'] as $name) {
      $queue = $this->queues->get($name);
      while ($done < $limit && microtime(TRUE) < $end && ($item = $queue->claimItem(300))) {
        try {
          $manager->createInstance($name)->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (\Throwable $exception) {
          $queue->releaseItem($item);
          $this->logger()->error($exception->getMessage());
        }
        $done++;
      }
    }
    $this->output()->writeln("Processed $done item(s).");
  }
}
