## Summary

`init-config` command fails in interactive mode while asking for output
directory for reports.

## Steps

In root directory of application execute:
```
bin/typo3-analyser init-config -i
```

## Expected
* input for output directory is accepted

## observed
* fatal error

```php
 Output directory for reports [tests/upgradeAnalysis]:
 > foo/bar

PHP Fatal error:  Uncaught TypeError: Cannot access offset of type array on array in /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Style/SymfonyStyle.php:279
Stack trace:
#0 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/src/Application/Command/InitConfigCommand.php(153): Symfony\Component\Console\Style\SymfonyStyle->choice('Report formats ...', Array, Array)
#1 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/src/Application/Command/InitConfigCommand.php(56): CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand->runInteractiveConfiguration(Object(Symfony\Component\Console\Style\SymfonyStyle), Array)
#2 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Command/Command.php(326): CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand->execute(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#3 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php(1078): Symfony\Component\Console\Command\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#4 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php(324): Symfony\Component\Console\Application->doRunCommand(Object(CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand), Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#5 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php(175): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))
#6 /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/bin/typo3-analyzer(39): Symfony\Component\Console\Application->run()
#7 {main}
  thrown in /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Style/SymfonyStyle.php on line 279

Fatal error: Uncaught TypeError: Cannot access offset of type array on array in /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Style/SymfonyStyle.php on line 279

TypeError: Cannot access offset of type array on array in /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Style/SymfonyStyle.php on line 279

Call Stack:
    0.0005     503192   1. {main}() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/bin/typo3-analyzer:0
    0.0767    8348176   2. CPSIT\UpgradeAnalyzer\Application\AnalyzerApplication->run() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/bin/typo3-analyzer:39
    0.0811    8693392   3. CPSIT\UpgradeAnalyzer\Application\AnalyzerApplication->doRun() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php:175
    0.0814    8688968   4. CPSIT\UpgradeAnalyzer\Application\AnalyzerApplication->doRunCommand() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php:324
    0.0814    8688968   5. CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand->run() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Application.php:1078
    0.0814    8691424   6. CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand->execute() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/vendor/symfony/console/Command/Command.php:326
    0.0843    9276352   7. CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand->runInteractiveConfiguration() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/src/Application/Command/InitConfigCommand.php:56
   15.2265    9417744   8. Symfony\Component\Console\Style\SymfonyStyle->choice() /Users/d.wenzel/projekt/dena/ventures/dena-de/v12/dena-bundle/tests/typo3-upgrade-analyzer/src/Application/Command/InitConfigCommand.php:153
```

## test cases

1. analyzer command is called from outside of its root
2. analyzer command is called from within its directory structure
3. output directory for reports is relative
4. output directory for reports is absolute
