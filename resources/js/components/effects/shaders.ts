export const fragment = `precision highp float;
uniform vec2 uRes; uniform float uTime; uniform float uCell; uniform vec2 uMouse; uniform float uStir;
uniform vec3 uC0, uC1, uC2, uC3;
float h(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
float n(vec2 p){ vec2 i = floor(p), f = fract(p); f = f * f * (3.0 - 2.0 * f);
  return mix(mix(h(i), h(i + vec2(1, 0)), f.x), mix(h(i + vec2(0, 1)), h(i + vec2(1, 1)), f.x), f.y); }
float fbm(vec2 p){ float v = 0.0, a = 0.5; for (int i = 0; i < 3; i++){ v += a * n(p); p = p * 2.03 + 11.7; a *= 0.5; } return v; }
float bayer4(vec2 c){
  vec2 m = mod(c, 4.0);
  int i = int(m.x + m.y * 4.0);
  float b[16];
  b[0]=0.;b[1]=8.;b[2]=2.;b[3]=10.;b[4]=12.;b[5]=4.;b[6]=14.;b[7]=6.;b[8]=3.;b[9]=11.;b[10]=1.;b[11]=9.;b[12]=15.;b[13]=7.;b[14]=13.;b[15]=5.;
  float r = 0.0;
  for (int k = 0; k < 16; k++) if (k == i) r = b[k];
  return (r + 0.5) / 16.0;
}
float field(vec2 p, float t){
  vec2 q = vec2(fbm(p + vec2(0.0, t)), fbm(p + vec2(5.2, 1.3) - t));
  vec2 r = vec2(fbm(p + 1.7 * q + vec2(1.7, 9.2) + 0.5 * t), fbm(p + 1.7 * q + vec2(8.3, 2.8)));
  return fbm(p + 1.5 * r);
}
void main(){
  vec2 cell = floor(gl_FragCoord.xy / uCell);
  vec2 uv = (cell * uCell + 0.5 * uCell) / uRes.y;
  vec2 p = uv * 0.75;
  vec2 m = uMouse / uRes.y * 0.75;
  vec2 d = p - m;
  float ang = uStir * exp(-dot(d, d) * 6.0) * 1.4;
  p = m + mat2(cos(ang), -sin(ang), sin(ang), cos(ang)) * d;
  float t = uTime * 0.03;
  float e = 0.004;
  float h0 = field(p, t);
  float hx = field(p + vec2(e, 0.0), t), hy = field(p + vec2(0.0, e), t);
  vec3 nrm = normalize(vec3((h0 - hx) / e * 0.09, (h0 - hy) / e * 0.09, 1.0) * vec3(9.0, 9.0, 1.0));
  vec3 L = normalize(vec3(-0.55, 0.45, 0.7));
  float diff = max(dot(nrm, L), 0.0);
  float spec = pow(max(dot(reflect(-L, nrm), vec3(0.0, 0.0, 1.0)), 0.0), 36.0);
  float b = pow(diff, 4.6) * 0.95 + spec * 0.85;
  b *= smoothstep(0.3, 0.56, h0 + 0.08);
  b = clamp(b, 0.0, 1.0);
  float lv = b * 3.0;
  float base = floor(lv);
  float tone = base + step(bayer4(cell), lv - base);
  vec3 col = tone < 0.5 ? uC0 : tone < 1.5 ? uC1 : tone < 2.5 ? uC2 : uC3;
  gl_FragColor = vec4(col, 1.0);
}`;
export const vertex = `attribute vec2 a; void main(){ gl_Position = vec4(a, 0.0, 1.0); }`;
