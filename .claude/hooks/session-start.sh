#!/bin/bash
# Install the skills from goa0646-coder/ponytail, goa0646-coder/skills and
# goa0646-coder/prompt-master into
# ~/.claude/skills so they are available in every cloud session.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

dest="$HOME/.claude/skills"
cache="$HOME/.cache/claude-skill-repos"
mkdir -p "$dest" "$cache"

# Find a local checkout of the repo, or shallow-clone it.
repo_dir() {
  local name="$1"
  for d in "${CLAUDE_PROJECT_DIR:-}" "${CLAUDE_PROJECT_DIR:-}/.." /home/user; do
    if [ -d "$d/$name/.git" ]; then echo "$d/$name"; return; fi
    if [ -d "$d/.git" ] && [ "$(basename "$(cd "$d" && pwd)")" = "$name" ]; then echo "$d"; return; fi
  done
  if [ -d "$cache/$name/.git" ]; then
    git -C "$cache/$name" pull -q --ff-only >/dev/null 2>&1 || true
  else
    git clone -q --depth 1 "https://github.com/goa0646-coder/$name" "$cache/$name" >/dev/null 2>&1 || return 0
  fi
  echo "$cache/$name"
}

install_skills() {
  local src="$1"
  [ -d "$src" ] || return 0
  for skill in "$src"/*/; do
    [ -f "$skill/SKILL.md" ] || continue
    rm -rf "$dest/$(basename "$skill")"
    cp -r "$skill" "$dest/"
  done
}

p=$(repo_dir ponytail); [ -n "$p" ] && install_skills "$p/skills"
s=$(repo_dir skills);   [ -n "$s" ] && install_skills "$s/skills"

# prompt-master keeps its SKILL.md at the repo root.
m=$(repo_dir prompt-master)
if [ -n "$m" ] && [ -f "$m/SKILL.md" ]; then
  rm -rf "$dest/prompt-master"
  mkdir -p "$dest/prompt-master"
  cp -r "$m/SKILL.md" "$m/references" "$dest/prompt-master/" 2>/dev/null || true
fi
exit 0
