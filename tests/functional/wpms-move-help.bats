#!/usr/bin/env bats

# Functional stub: verify wpms:move --help output contains expected flags.
# Requires: TERMINUS_PLUGINS_DIR=.. and terminus in PATH.

@test "wpms:move --help shows usage" {
  skip "Stub — requires a live Terminus installation to run"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"source_site_env"* ]]
  [[ "$output" == *"target_site_env"* ]]
  [[ "$output" == *"site_id"* ]]
}

@test "wpms:move --help shows --dry-run flag" {
  skip "Requires a live Terminus installation to run (implemented in B3)"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"--dry-run"* ]]
}

@test "wpms:move --help shows --yes flag" {
  skip "Requires a live Terminus installation to run (B1 uses global Terminus --yes via isInteractive())"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"--yes"* ]]
}

@test "wpms:move --help shows --domain flag" {
  skip "Stub — implement after Phase C (C1)"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"--domain"* ]]
}

@test "wpms:move --help shows --filesync-mode flag" {
  skip "Requires a live Terminus installation to run"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"--filesync-mode"* ]]
}

@test "wpms:move --help shows --filesync-verbose flag" {
  skip "Requires a live Terminus installation to run"
  run terminus help wpms:move
  [ "$status" -eq 0 ]
  [[ "$output" == *"--filesync-verbose"* ]]
}
