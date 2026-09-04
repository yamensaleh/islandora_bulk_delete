<?php
declare(strict_types=1);
namespace Drupal\islandora_bulk_delete\Commands;
use Drush\Commands\DrushCommands;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
final class BulkDeleteCommands extends DrushCommands {
  public function __construct(private readonly QueueFactory $queues) { parent::__construct(); }
  public static function create(ContainerInterface $c): static { return new static($c->get('queue')); }
  /** Process queued Islandora deletions. @command islandora:bulk-delete:process @option limit Maximum items. @option time-limit Seconds. */
  public function process(int $limit=100,int $time_limit=50): void { $q=$this->queues->get('islandora_bulk_delete');$done=0;$end=microtime(TRUE)+$time_limit;while($done<$limit&&microtime(TRUE)<$end&&($item=$q->claimItem(300))){try{\Drupal::service('plugin.manager.queue_worker')->createInstance('islandora_bulk_delete')->processItem($item->data);$q->deleteItem($item);}catch(\Throwable $e){$q->releaseItem($item);$this->logger()->error($e->getMessage());}$done++;} $this->output()->writeln("Processed $done item(s)."); }
}
