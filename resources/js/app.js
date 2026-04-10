import './bootstrap';
import registerAgentChat from './agent-chat';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

registerAgentChat(Alpine);

Alpine.start();
