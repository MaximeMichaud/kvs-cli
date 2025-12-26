# kvs completion

Generate shell completion scripts.

## Synopsis

```bash
kvs completion [<shell>]
```

## Description

The `completion` command generates shell completion scripts for bash, zsh, and fish shells. This enables tab completion for commands, options, and arguments.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `shell` | No | Shell type: bash, zsh, fish (auto-detected if omitted) |

## Installation

### Bash

**System-wide (requires sudo):**

```bash
kvs completion bash | sudo tee /etc/bash_completion.d/kvs > /dev/null
source /etc/bash_completion.d/kvs
```

**User-only:**

```bash
kvs completion bash >> ~/.bash_completion
source ~/.bash_completion
```

**Dynamic loading (add to ~/.bashrc):**

```bash
eval "$(kvs completion bash)"
```

### Zsh

**Using completion directory:**

```bash
mkdir -p ~/.zsh/completion
kvs completion zsh > ~/.zsh/completion/_kvs
```

Add to `~/.zshrc`:

```bash
fpath=(~/.zsh/completion $fpath)
autoload -Uz compinit && compinit
```

**Dynamic loading (add to ~/.zshrc):**

```bash
eval "$(kvs completion zsh)"
```

### Fish

```bash
kvs completion fish > ~/.config/fish/completions/kvs.fish
```

## Examples

### Generate and Test

```bash
# Generate for current shell
kvs completion

# Generate for specific shell
kvs completion bash
kvs completion zsh
kvs completion fish
```

### Quick Setup

```bash
# Bash - add to ~/.bashrc
echo 'eval "$(kvs completion bash)"' >> ~/.bashrc
source ~/.bashrc

# Zsh - add to ~/.zshrc
echo 'eval "$(kvs completion zsh)"' >> ~/.zshrc
source ~/.zshrc
```

## Features

Tab completion works for:

- **Commands**: `kvs vid<TAB>` → `kvs video`
- **Subcommands**: `kvs video <TAB>` → `list`, `show`
- **Options**: `kvs video list --<TAB>` → `--limit`, `--status`, etc.
- **Arguments**: Context-aware suggestions

## See Also

- [Installation](../installation.md) - Full installation guide
