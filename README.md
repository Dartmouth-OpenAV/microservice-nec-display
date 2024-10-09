# NEC display microservice

RESTful microservice for controlling NEC flat panel displays

The desktop application "[NEC PD Comms Tool](https://www.sharpnecdisplays.eu/p/eeme/en/products/software/details/t/Software/Displays/rp/PDCommsTool.xhtml)" is very useful for testing and debugging.  It contains a send/receive communications log so you can see all the commands and responses.

End Point - http://localhost:8383/{{host}}  
  
**Get display information**  
method: GET  
body: none  
respones:  
`{
  "power_status": 1,
  "power_status_description": "on",
  "video_input_num": "2",
  "video_input_type": "hdmi",
  "audio_volume": 10,
  "audio_target": "speaker",
  "audio_muted": false,  
  "max_volume": 100,
  "min_volume": 0
}`
  
**Set power**  
method: PUT  
body:  
`{"power_state": "on|off"}`  
response:  
`{
  "power_status": 1,
  "power_status_description": "on"
}`  
  
**Set input**  
method: PUT  
body:  
`{
	"video_input_num": 1|2|3
}`  
response:  
`{
  "video_input_num": "3",
  "video_input_type": "hdmi"
}`  
  
**Set volume to specific value or step volume**  
method: PUT  
body:  
`{"audio_volume": 5}`  
response:  
`{
  "audio_volume": 15,
  "audio_target": "speaker",
  "audio_muted": false,
  "max_volume": 100,
  "min_volume": 0
}`  
  
**Mute or unmute**  
method: PUT  
body:  
`{"audio_mute": true|false}`  
response:  
`{"audio_muted":"true"}`  
