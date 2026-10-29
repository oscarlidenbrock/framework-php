<?php

declare(strict_types=1);

use Framework\Config\InstallationSnapshot;
use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Http\HttpKernel;
use Framework\Log\LogReader;
use Framework\Log\LoggerFactory;
use Framework\Runtime\RuntimePaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RuntimeLogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-runtime-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testWorkspaceSnapshotWriteReadAndRemove(): void
    {
        $snapshot = new InstallationSnapshot($this->root, 'first');
        self::assertNull($snapshot->read());
        $updated = ['enabled_modules' => ['user', 'admin'], 'active_theme' => 'default', 'admin_theme' => 'admin_theme'];
        $snapshot->write($updated);
        self::assertSame($updated, $snapshot->read());
        self::assertFileExists($this->root . '/var/cache/first/installation.json');
        self::assertNull((new InstallationSnapshot($this->root, 'second'))->read());
        $snapshot->remove();
        self::assertNull($snapshot->read());
    }

    public function testSnapshotIgnoresObsoleteCachePath(): void
    {
        mkdir($this->root . '/storage/cache/installation', 0775, true);
        file_put_contents($this->root . '/storage/cache/installation/first.json', json_encode([
            'enabled_modules' => ['old'], 'active_theme' => 'old', 'admin_theme' => 'old',
        ], JSON_THROW_ON_ERROR));
        self::assertNull((new InstallationSnapshot($this->root, 'first'))->read());
    }

    public function testWorkspaceLogRotationRedactionAndBoundedRead(): void
    {
        $firstPaths = new RuntimePaths($this->root, 'first');
        $logger = (new LoggerFactory($firstPaths, 4096, 2))->channel('user');
        for ($index = 0; $index < 60; ++$index) {
            $logger->notice('Login failed', ['attempt' => $index, 'password' => 'hidden-value', 'nested' => ['csrf_token' => 'secret-value']]);
        }
        self::assertFileExists($this->root . '/var/logs/first/user.log.1');
        self::assertFileDoesNotExist($this->root . '/var/logs/first/user.log.3');
        $text = file_get_contents($this->root . '/var/logs/first/user.log');
        self::assertStringNotContainsString('hidden-value', $text);
        self::assertStringNotContainsString('secret-value', $text);
        $reader = new LogReader($firstPaths);
        self::assertCount(5, $reader->recent('user', 'notice', 5));
        $id = $reader->recent('user', 'notice', 1)[0]['id'];
        self::assertSame($id, $reader->detail($id)['id']);
        self::assertSame([], (new LogReader(new RuntimePaths($this->root, 'second')))->recent());
    }

    public function testUnexpectedKernelErrorLogsClassWithoutExceptionMessage(): void
    {
        $container = new ContainerBuilder();
        $container->set('broken', new class () {
            public function __invoke(): never
            {
                throw new RuntimeException('secret connection password');
            }
        });
        $routes = new RouteCollection();
        $routes->add('broken', new Route('/broken', ['_controller' => 'broken', '_access' => 'public'], methods: ['GET']));
        $paths = new RuntimePaths($this->root, 'first');
        $logger = (new LoggerFactory($paths))->channel('kernel');
        $assets = new AssetManager(new LibraryResolver(new LibraryRegistry($this->root, [], [])), []);
        $response = (new HttpKernel($routes, $container, $assets, logger: $logger))->handle(Request::create('http://localhost/broken'));
        self::assertSame(500, $response->getStatusCode());
        $contents = file_get_contents($this->root . '/var/logs/first/kernel.log');
        self::assertStringContainsString('RuntimeException', $contents);
        self::assertStringNotContainsString('secret connection password', $contents);
    }

    public function testUnwritableLogDestinationDoesNotRaiseAnotherError(): void
    {
        mkdir($this->root . '/var/logs', 0775, true);
        file_put_contents($this->root . '/var/logs/first', 'occupied');
        $logger = (new LoggerFactory(new RuntimePaths($this->root, 'first')))->channel('application');
        $logger->error('Cannot write');
        self::assertFileDoesNotExist($this->root . '/var/logs/first/application.log');
    }

    public function testRecentReaderSkipsLargeOlderContent(): void
    {
        $paths = new RuntimePaths($this->root, 'first');
        mkdir($paths->logs(), 0775, true);
        file_put_contents($paths->logs('application.log'), str_repeat('x', 300000) . "\n");
        (new LoggerFactory($paths, 1048576))->channel('application')->notice('Newest entry');
        $entries = (new LogReader($paths))->recent(limit: 1);
        self::assertCount(1, $entries);
        self::assertSame('Newest entry', $entries[0]['message']);
    }

    public function testWorkspaceRuntimeDirectoryCannotPointAtAnotherWorkspace(): void
    {
        mkdir($this->root . '/var/logs/second', 0775, true);
        symlink($this->root . '/var/logs/second', $this->root . '/var/logs/first');
        $this->expectException(RuntimeException::class);
        (new RuntimePaths($this->root, 'first'))->logs('user.log');
    }
}
