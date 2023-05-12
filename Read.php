<?php

namespace Drupal\piliskor_qr\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\piliskor_qr\ReadManager;
use Drupal\piliskor_run\RunManager;
use Drupal\user\UserInterface;
use malkusch\lock\mutex\MySQLMutex;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the reader callback.
 */
class Read implements ContainerInjectionInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The read manager.
   *
   * @var \Drupal\piliskor_qr\ReadManager
   */
  protected $readManager;

  /**
   * The run manager.
   *
   * @var \Drupal\piliskor_run\RunManager;
   */
  protected $runManager;

  /**
   * The db connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;


  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\piliskor_qr\ReadManager $read_manager
   *   The read plugin manager.
   * @param \Drupal\piliskor_run\RunManager $run_manager
   *   The run manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(AccountProxyInterface $current_user, ReadManager $read_manager, RunManager $run_manager, Connection $connection) {
    $this->currentUser = $current_user;
    $this->readManager = $read_manager;
    $this->runManager = $run_manager;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('plugin.manager.piliskor_qr_read'),
      $container->get('piliskor_run.run_manager'),
      $container->get('database')
    );
  }

  /**
   * Access check.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $run
   *   The run.
   */
  public function access(OrderItemInterface $run, UserInterface $account, RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName();
    $plugin_id = explode('.', $route_name)[1];
    /** @var \Drupal\piliskor_qr\Plugin\PiliskorRead\ReadInterface $plugin */
    $plugin = $this->readManager->createInstance($plugin_id);
    return $plugin->access('read', $account);
  }

  /**
   * Handle a qr reading.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $run
   *   The run.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response object.
   */
  public function read(OrderItemInterface $run, UserInterface $account, Request $request, RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName();
    $plugin_id = explode('.', $route_name)[1];
    $instance = $this->readManager->createInstance($plugin_id);
    // Drupal is not great at concurrent entity creation. Concurrent reads can
    // be performed by two different runners at the same time or (maybe) a
    // single runner clicking the GPS button twice too fast. The first can
    // result in deadlocks that is solved by setting the InnoDB transaction
    // isolation to READ COMMITTED. The second one yields multiple valid reads
    // at the same point by the same runner, which should be avoided. Using
    // the mutex below prevents concurrent execution of the read creation code.
    $connection_information = $this->connection->getConnectionOptions();
    $pdo = $this->connection->open($connection_information);
    $mutex = new MySQLMutex($pdo, "piliskor", 15);
    return $mutex->synchronized(function () use ($instance, $run, $account, $request, $route_match) {
      return $instance->createResponse($run, $account, $request, $route_match);
    });
  }

}
