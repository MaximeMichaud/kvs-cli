# PHPStan Level 8 Migration Guide

## Context

PHPStan level 8 requires strict null checking. The main issue in Symfony Console commands is that `$this->io` (SymfonyStyle) is nullable because it's initialized in `initialize()` method, not in the constructor.

## Problem

```php
// BaseCommand.php
protected ?SymfonyStyle $io = null;

protected function initialize(InputInterface $input, OutputInterface $output): void
{
    $this->io = new SymfonyStyle($input, $output);
}
```

At level 8, PHPStan reports ~500 errors like:
```
Cannot call method success() on Symfony\Component\Console\Style\SymfonyStyle|null.
```

## Solution: Getter with Assert

Add a getter method that guarantees non-null return:

```php
protected function io(): SymfonyStyle
{
    assert($this->io !== null);
    return $this->io;
}
```

Then replace all `$this->io->method()` calls with `$this->io()->method()`.

## Why This Approach?

### Alternatives Considered

1. **Symfony 7.3+ Invokable Commands** - Modern but requires Symfony 7.3+
   - Reference: https://symfony.com/blog/new-in-symfony-7-3-invokable-commands-and-input-attributes

2. **SymfonyStyle as DI Service** - Possible but adds complexity
   - Reference: https://tomasvotruba.com/blog/2018/08/06/stylish-and-standard-console-output-with-symfony-style

3. **phpstan-symfony extension** - Doesn't handle this specific case
   - Reference: https://github.com/phpstan/phpstan-symfony

### Why Getter with Assert?

- Works with any Symfony version
- Minimal code changes (search & replace)
- Assert is optimized away in production (if assertions disabled)
- Common pattern in strict PHP codebases
- No architectural changes required

## Implementation

```bash
# 1. Add getter to BaseCommand
# 2. Replace all occurrences:
sed -i 's/\$this->io->/\$this->io()->/g' src/Command/*.php
```

## References

- PHPStan Rule Levels: https://phpstan.org/user-guide/rule-levels
- PHPStan Symfony: https://github.com/phpstan/phpstan-symfony
- Martin Hujer's PHPStan Symfony Guide: https://blog.martinhujer.cz/how-to-configure-phpstan-for-symfony-applications/

---
*Created: 2024-12-26*
*PHPStan: Level 7 -> Level 8*
