#!/usr/bin/env bats

# Functional stub: verify the plugin is loadable by Terminus.
# Requires: TERMINUS_PLUGINS_DIR=.. and terminus in PATH.

@test "terminus loads without errors" {
  skip "Stub — requires a live Terminus + Pantheon credentials to run"
  run terminus list
  [ "$status" -eq 0 ]
}

@test "wpms commands appear in terminus list" {
  skip "Stub — requires a live Terminus + Pantheon credentials to run"
  run terminus list
  [[ "$output" == *"wpms:move"* ]]
  [[ "$output" == *"wpms:rsync"* ]]
  [[ "$output" == *"wpms:delete"* ]]
}
