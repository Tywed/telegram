# Webtrees Module: Telegram Notifications

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](http://www.gnu.org/licenses/gpl-3.0)
![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.x-green)
![Latest Release](https://img.shields.io/badge/release-v0.1.2-blue)

This [webtrees](https://www.webtrees.net/) custom module sends Telegram notifications about significant family events such as birthdays and anniversaries based on the data from your webtrees installation.

## Contents
This Readme contains the following main sections:

* [Warning](#warning)
* [Description](#description)
* [Screenshots](#screenshots)
* [Requirements](#requirements)
* [Installation](#installation)
* [Upgrade](#upgrade)
* [Support](#support)
* [License](#license)

<a name="warning"></a>
## Warning

Before installing this module on your main site, we recommend testing it on a staging environment. This module interacts with external services (Telegram API) and may require specific configuration of your Telegram bot and permissions.

<a name="description"></a>
## Description

This custom module for webtrees integrates with Telegram to send notifications about today's significant family events, such as:

- **Birthdays**: Notifies about individuals' birthdays.
- **Marriage Anniversaries**: Notifies about marriage anniversaries.

The module is designed to be easily configurable from the webtrees admin panel. You can configure it to send notifications to a specific Telegram chat (or group) with your preferred Telegram Bot.

### Features:
- **Telegram Bot Integration**: Allows sending messages using a Telegram bot.
- **Event Types**: Supports notifications for birthdays and marriage anniversaries.
- **Configuration**: Set your Telegram Bot Token and chat ID in the module settings.
- **User and Tree Preferences**: Specify the user and family tree for which you want to send notifications.

<a name="screenshots"></a>
## Screenshots

Screenshot of settings module
<p align="center"><img src="docs/settings.JPG" alt="Screenshot of settings module" align="center" width="80%"></p>

Screenshot of the message in telegram
<p align="center"><img src="docs/message.JPG" alt="Screenshot of the message in telegram" align="center" width="85%"></p>

<a name="requirements"></a>
## Requirements

This module requires **webtrees** version 2.2 or later.
This module has the same requirements as [webtrees system requirements](https://github.com/fisharebest/webtrees#system-requirements).

This module was tested with **webtrees** 2.2.1 and later versions.

### Telegram Bot:
- Create a Telegram bot using [BotFather](https://core.telegram.org/bots/tutorial#obtain-your-bot-token).
- Obtain your bot's token and chat ID.

### Cron Job:
To ensure that the notifications are sent regularly (e.g., daily at midnight), you need to set up a **cron job** on your server. The link for the cron must be taken from the module settings. This will allow the script to check for events and send the corresponding notifications.

<a name="installation"></a>
## Installation

Follow these steps to install the module:

1. Download the [latest release](https://github.com/tywed/telegram/releases/latest).
2. Unzip the package into your `webtrees/modules_v4` directory.
3. Log in to **webtrees** as an administrator and go to <span class="pointer">Control Panel / Modules / Telegram</span>.
4. In the settings, enter your **Telegram Bot Token** and **Telegram Chat ID**.
5. Set the **User** and **Tree** from which you want to send the events.
6. Enable the module and click **Save**.
7. **Set up a cron job** on your server to trigger the module at regular intervals (e.g., daily).

<a name="upgrade"></a>
## Upgrade

To update the module:

1. Download and unzip the latest release.
2. Replace the existing `telegram` folder in your `modules_v4` directory with the new version.
3. No further configuration should be needed, but double-check the settings in the module to ensure everything is up to date.

<a name="support"></a>
## Support

- **Issues**: Report any bugs or issues by opening an issue on the [GitHub repository](https://github.com/tywed/telegram).
- **Forum**: General support for webtrees can be found on the [webtrees forum](http://www.webtrees.net/).

<a name="license"></a>
## License

* Copyright © 2024 Tywed

This module was developed of the [webtrees-reminder](https://github.com/UksusoFF/webtrees-reminder) module by Kirill Uksusov (UksusoFF).

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public License](http://www.gnu.org/licenses/) for more details.

* * *
