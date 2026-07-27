#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="$root_dir/.env"
mode="${1:---once}"
[[ "$mode" == "--once" || "$mode" == "--dry-run" ]] || { echo "Usage: $(basename "$0") [--once|--dry-run]" >&2; exit 2; }
[[ -f "$env_file" ]] || { echo "Missing $env_file" >&2; exit 1; }
command -v php >/dev/null || { echo "Missing php command." >&2; exit 1; }
command -v curl >/dev/null || { echo "Missing curl command." >&2; exit 1; }
command -v docker >/dev/null || { echo "Missing docker command." >&2; exit 1; }
docker compose version >/dev/null

env_value() { sed -n "s/^$1=//p" "$env_file" | head -n 1 | sed -e 's/^['\''"]//' -e 's/['\''"]$//'; }
appstore_url="$(env_value APPSTORE_INTERNAL_URL)"; instance_id="$(env_value APPSTORE_INSTANCE_ID)"
agent_id="$(env_value APPSTORE_AGENT_ID)"; agent_token="$(env_value APPSTORE_AGENT_TOKEN)"
[[ -n "$appstore_url" && -n "$instance_id" && -n "$agent_id" && -n "$agent_token" ]] || { echo "Missing AppStore Agent configuration." >&2; exit 1; }
appstore_url="${appstore_url%/}"

json_path() { php -r '$v=json_decode(stream_get_contents(STDIN),true);foreach(explode(".",$argv[1]) as $k){if(!is_array($v)||!array_key_exists($k,$v))exit(1);$v=$v[$k];}if(is_array($v))echo json_encode($v,JSON_UNESCAPED_SLASHES);elseif($v!==null)echo $v;' "$1"; }
request() {
  local method="$1" path="$2" body="${3:-}"
  local args=(-fsS -X "$method" "$appstore_url$path" -H "X-YeYing-Instance: $instance_id" -H "X-YeYing-Agent: $agent_id" -H "Authorization: Bearer $agent_token" -H "Accept: application/json")
  [[ -n "$body" ]] && args+=(-H "Content-Type: application/json" --data "$body")
  curl "${args[@]}"
}
report_status() {
  local status="$1" result="${2:-{}}" response
  response="$(request POST "/api/v1/runtime/tasks/$task_id/report" "$(php -r 'echo json_encode(["revision"=>(int)$argv[1],"status"=>$argv[2],"result"=>json_decode($argv[3],true)?:new stdClass]);' "$revision" "$status" "$result")")"
  revision="$(printf '%s' "$response" | json_path data.revision)"
  task_status="$status"
}
heartbeat() {
  local response
  response="$(request POST "/api/v1/runtime/tasks/$task_id/heartbeat" "{\"revision\":$revision}")"
  revision="$(printf '%s' "$response" | json_path data.revision)"
}

verify_and_extract_release() {
  local source="$1" expected="$2" destination="$3"
  php -r '
    $payload=json_decode(file_get_contents($argv[1]),true);if(($payload["code"]??0)!==200||!is_array($payload["data"]??null))exit(1);$data=$payload["data"];
    if(($data["release_digest"]??"")!==$argv[2]||!is_array($data["files"]??null))exit(1);$files=$data["files"];
    foreach(["application.json","runtime.json","config.schema.json","permissions.json","compose.yaml","checksums.json","signature.json"] as $required)if(!array_key_exists($required,$files))exit(1);
    $checksums=json_decode($files["checksums.json"],true);if(!is_array($checksums))exit(1);
    foreach($checksums as $path=>$sum){if(str_contains($path,"..")||str_starts_with($path,"/")||str_contains($path,"\\")||!isset($files[$path])||!is_string($sum)||!hash_equals($sum,hash("sha256",$files[$path])))exit(1);}
    $runtime=json_decode($files["runtime.json"],true);if(!is_array($runtime)||!preg_match("/^[^\\s]+@sha256:[a-f0-9]{64}$/",(string)($runtime["image"]??"")))exit(1);
    if(!is_dir($argv[3])&&!mkdir($argv[3],0755,true))exit(1);
    foreach($files as $path=>$content){if(str_contains($path,"..")||str_starts_with($path,"/")||str_contains($path,"\\"))exit(1);$target=$argv[3]."/".$path;$dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0755,true))exit(1);if(file_put_contents($target,$content)===false)exit(1);}
  ' "$source" "$expected" "$destination"
}

runtime_value() { php -r '$v=json_decode(file_get_contents($argv[1]),true);foreach(explode(".",$argv[2]) as $k){if(!is_array($v)||!array_key_exists($k,$v))exit(1);$v=$v[$k];}echo is_bool($v)?($v?"true":"false"):$v;' "$release_dir/runtime.json" "$1"; }
write_runtime_env() {
  php -r '
    $runtime=json_decode(file_get_contents($argv[1]),true);$source=[];foreach(file($argv[2],FILE_IGNORE_NEW_LINES)?:[] as $line){if($line===""||$line[0]==="#"||!str_contains($line,"="))continue;[$key,$value]=explode("=",$line,2);$source[trim($key)]=trim($value," \t\n\r\0\x0B\"\047");}
    $output=[];foreach(($runtime["environment"]??[]) as $item){$value=$source[$item["from_env"]]??"";if(($item["required"]??false)&&$value===""){fwrite(STDERR,"Missing required Project env: ".$item["from_env"].PHP_EOL);exit(1);}$output[]=$item["name"]."=".str_replace(["\\","\n","\r"],["\\\\","\\n",""],$value);}
    if(file_put_contents($argv[3].".tmp",implode(PHP_EOL,$output).PHP_EOL)===false||!rename($argv[3].".tmp",$argv[3]))exit(1);chmod($argv[3],0600);
  ' "$release_dir/runtime.json" "$env_file" "$release_dir/runtime.env"
}
validate_compose() {
  local rendered="$release_dir/compose.rendered.json"
  docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" config --format json >"$rendered"
  php -r '
    $compose=json_decode(file_get_contents($argv[1]),true);$runtime=json_decode(file_get_contents($argv[2]),true);if(!is_array($compose)||!is_array($runtime))exit(1);
    $name=$runtime["service"]["name"]??"";$services=$compose["services"]??[];if(count($services)!==1||!isset($services[$name]))exit(1);$service=$services[$name];
    if(($service["image"]??"")!==($runtime["image"]??""))exit(1);foreach(["privileged","devices","container_name","pid","network_mode"] as $key)if(!empty($service[$key]))exit(1);
    foreach(($service["volumes"]??[]) as $volume)if(($volume["type"]??"")!=="volume")exit(1);
    $host=(int)($runtime["service"]["host_port"]??0);$container=(int)($runtime["service"]["container_port"]??0);$matched=false;
    foreach(($service["ports"]??[]) as $port){if((int)($port["published"]??0)===$host&&(int)($port["target"]??0)===$container&&in_array(($port["host_ip"]??""),["127.0.0.1","::1"],true))$matched=true;}
    if(!$matched)exit(1);
  ' "$rendered" "$release_dir/runtime.json" || { echo "Rendered Compose violates Runtime Agent policy." >&2; return 1; }
}
check_dependencies() {
  php -r '$r=json_decode(file_get_contents($argv[1]),true);foreach(($r["dependencies"]??[]) as $d)if(!empty($d["required"]))echo $d["capability"].PHP_EOL;' "$release_dir/runtime.json" | while IFS= read -r capability; do
    case "$capability" in
      mysql) tcp_check mysql "$(env_value DB_HOST)" "$(env_value DB_PORT)" ;;
      redis) tcp_check redis "$(env_value REDIS_HOST)" "$(env_value REDIS_PORT)" ;;
      manticore) tcp_check manticore "$(env_value SEARCH_HOST)" "$(env_value SEARCH_PORT)" ;;
      project-api) local url host port; url="$(env_value APP_URL)"; host="$(printf '%s' "$url"|sed -E 's#^[a-z]+://##;s#/.*$##;s/:.*$//')"; port="$(printf '%s' "$url"|sed -nE 's#^[a-z]+://[^/:]+:([0-9]+).*#\1#p')"; tcp_check project-api "$host" "${port:-$(env_value LARAVELS_LISTEN_PORT)}" ;;
      object-storage) [[ -n "$(env_value S3_ENDPOINT)" ]] || { echo "Missing S3_ENDPOINT" >&2; return 1; } ;;
      *) echo "Unsupported declared dependency: $capability" >&2; return 1 ;;
    esac
  done
}
tcp_check() { php -r '$s=@fsockopen($argv[1],(int)$argv[2],$e,$m,3);if(!$s)exit(1);fclose($s);' "$2" "$3" || { echo "Dependency unavailable: $1 ($2:$3)" >&2; return 1; }; }
health_check() {
  local protocol path timeout codes deadline code
  protocol="$(runtime_value healthcheck.protocol)"; path="$(runtime_value healthcheck.path)"; timeout="$(runtime_value healthcheck.timeout_seconds)"
  codes="$(php -r '$r=json_decode(file_get_contents($argv[1]),true);echo implode(",",$r["healthcheck"]["success_codes"]??[200]);' "$release_dir/runtime.json")"
  deadline=$((SECONDS + timeout))
  while (( SECONDS < deadline )); do
    code="$(curl -ksS -o /dev/null -w '%{http_code}' --max-time 5 "$protocol://127.0.0.1:$host_port$path" || true)"
    [[ ",$codes," == *",$code,"* ]] && return 0
    heartbeat || true
    sleep 2
  done
  return 1
}
write_local_state() {
  local status="$1"
  php -r '
    require $argv[1]."/vendor/autoload.php";$application=json_decode(file_get_contents($argv[2]."/application.json"),true);$entries=$application["spec"]["entries"]??[];$menus=[];
    foreach($entries as $entry)$menus[]=["key"=>$entry["id"]??"","location"=>$entry["location"]??"application","label"=>$entry["label"]??[],"url"=>ltrim($entry["path"]??"","/"),"url_type"=>$entry["render"]??"iframe","visible_to"=>$entry["visibility"]??"all"];
    $config=["status"=>$argv[5],"install_version"=>$argv[3],"release_digest"=>$argv[4],"menu_items"=>$menus];$dir=$argv[1]."/docker/appstore/config/".$application["metadata"]["id"];
    if(!is_dir($dir)&&!mkdir($dir,0755,true))exit(1);$target=$dir."/config.yml";file_put_contents($target.".tmp",Symfony\Component\Yaml\Yaml::dump($config,4,2));if(!rename($target.".tmp",$target))exit(1);
    $stateDir=$argv[1]."/docker/appstore/runtime/".$application["metadata"]["id"];if(!is_dir($stateDir)&&!mkdir($stateDir,0755,true))exit(1);$state=["app_id"=>$application["metadata"]["id"],"version"=>$argv[3],"release_digest"=>$argv[4],"release_dir"=>$argv[2],"updated_at"=>gmdate(DATE_ATOM)];file_put_contents($stateDir."/state.json.tmp",json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));if(!rename($stateDir."/state.json.tmp",$stateDir."/state.json"))exit(1);
  ' "$root_dir" "$release_dir" "$version" "$release_digest" "$status"
}
load_state_value() { php -r '$s=json_decode(@file_get_contents($argv[1]),true);$v=$s[$argv[2]]??"";if($v!=="")echo $v;' "$state_file" "$1"; }
write_proxy_config() {
  local route_prefix
  route_prefix="$(runtime_value service.route_prefix)"
  [[ "$route_prefix" == "/apps/$app_id/" ]] || { echo "Invalid runtime route prefix." >&2; return 1; }
  mkdir -p "$root_dir/docker/appstore/config/$app_id"
  cat >"$root_dir/docker/appstore/config/$app_id/nginx.conf.tmp" <<EOF
location $route_prefix {
    proxy_pass http://127.0.0.1:$host_port/;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
}
EOF
  mv "$root_dir/docker/appstore/config/$app_id/nginx.conf.tmp" "$root_dir/docker/appstore/config/$app_id/nginx.conf"
}
reload_nginx() { command -v nginx >/dev/null && nginx -t && nginx -s reload; }
remove_local_state() { rm -f "$state_file"; rm -rf "$root_dir/docker/appstore/config/$app_id"; }

task_response="$(request POST /api/v1/runtime/tasks/claim)"; task_id="$(printf '%s' "$task_response" | json_path data.task_id || true)"
[[ -n "$task_id" ]] || { echo "No pending AppStore runtime task."; exit 0; }
revision="$(printf '%s' "$task_response" | json_path data.revision)"; app_id="$(printf '%s' "$task_response" | json_path data.app_id)"
operation="$(printf '%s' "$task_response" | json_path data.operation)"; version="$(printf '%s' "$task_response" | json_path data.target_version)"; release_digest="$(printf '%s' "$task_response" | json_path data.release_digest)"
[[ "$app_id" =~ ^[a-z][a-z0-9-]{1,63}$ && "$version" =~ ^[0-9A-Za-z.-]+$ && "$release_digest" =~ ^sha256:[a-f0-9]{64}$ ]] || { echo "Invalid runtime task identity." >&2; exit 1; }
[[ "$operation" == "install" || "$operation" == "upgrade" || "$operation" == "uninstall" ]] || { echo "Invalid runtime operation." >&2; exit 1; }
compose_project="yeying-app-$app_id"; state_file="$root_dir/docker/appstore/runtime/$app_id/state.json"; previous_release_dir="$(load_state_value release_dir)"; previous_version="$(load_state_value version)"; previous_digest="$(load_state_value release_digest)"
claimed=1; task_status=claimed; release_response="$(mktemp)"
return_task() { [[ "$claimed" == 1 && "$task_status" == claimed ]] || return 0; request POST "/api/v1/runtime/tasks/$task_id/release" "{\"revision\":$revision}" >/dev/null || true; }
trap 'rm -f "$release_response"; return_task' EXIT

if [[ "$operation" != "uninstall" ]]; then
  release_dir="$root_dir/docker/appstore/releases/$app_id/$version/$release_digest"
  request GET "/api/v1/runtime/releases/$app_id/$version" >"$release_response"
  verify_and_extract_release "$release_response" "$release_digest" "$release_dir"
  write_runtime_env
  service_name="$(runtime_value service.name)"; host_port="$(runtime_value service.host_port)"
  validate_compose; check_dependencies
else
  [[ -n "$previous_release_dir" && -f "$previous_release_dir/compose.yaml" ]] || { echo "Missing local release state for uninstall." >&2; exit 1; }
  release_dir="$previous_release_dir"; write_runtime_env; service_name="$(runtime_value service.name)"; host_port="$(runtime_value service.host_port)"
  validate_compose
fi

if [[ "$mode" == "--dry-run" ]]; then
  echo "Dry-run passed for $operation $app_id@$version ($release_digest). Task returned to pending."
  exit 0
fi
report_status applying '{}'; claimed=0

if [[ "$operation" == "uninstall" ]]; then
  [[ -n "$previous_release_dir" && -f "$previous_release_dir/compose.yaml" ]] || { report_status failed '{"code":"LOCAL_STATE_MISSING"}'; exit 1; }
  release_dir="$previous_release_dir"; write_runtime_env; service_name="$(runtime_value service.name)"; host_port="$(runtime_value service.host_port)"
  if docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" down --remove-orphans; then
    remove_local_state
    reload_nginx
    report_status succeeded "{\"release_digest\":\"$release_digest\",\"uninstalled\":true,\"data_preserved\":true}"
    exit 0
  fi
  report_status failed "{\"release_digest\":\"$release_digest\",\"code\":\"UNINSTALL_FAILED\"}"
  exit 1
fi

deploy_failed=0
docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" pull || deploy_failed=1
heartbeat || true
if [[ "$deploy_failed" == 0 ]]; then docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" up -d --remove-orphans || deploy_failed=1; fi
if [[ "$deploy_failed" == 0 ]]; then
  report_status verifying '{}'
  if health_check; then
    if write_proxy_config && reload_nginx && write_local_state installed; then
      report_status succeeded "{\"release_digest\":\"$release_digest\",\"healthcheck\":{\"ok\":true},\"previous_version\":\"$previous_version\"}"
      exit 0
    fi
  fi
fi

if [[ -n "$previous_release_dir" && -f "$previous_release_dir/compose.yaml" ]]; then
  report_status rolling_back "{\"release_digest\":\"$release_digest\"}"
  release_dir="$previous_release_dir"; write_runtime_env; service_name="$(runtime_value service.name)"; host_port="$(runtime_value service.host_port)"
  version="$previous_version"; release_digest="$previous_digest"
  if validate_compose && docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" up -d --remove-orphans && health_check && write_proxy_config && reload_nginx && write_local_state installed; then
    report_status rolled_back "{\"release_digest\":\"$release_digest\",\"rollback\":{\"succeeded\":true}}"
    exit 1
  fi
  report_status rollback_failed "{\"release_digest\":\"$release_digest\",\"rollback\":{\"succeeded\":false}}"
  exit 1
fi

docker compose --env-file "$release_dir/runtime.env" -p "$compose_project" -f "$release_dir/compose.yaml" down --remove-orphans >/dev/null 2>&1 || true
remove_local_state
reload_nginx
report_status failed "{\"release_digest\":\"$release_digest\",\"code\":\"DEPLOYMENT_FAILED\",\"rollback\":{\"attempted\":false}}"
exit 1
